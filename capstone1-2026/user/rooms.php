<?php
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/availability.php';

/* ── URL params from dashboard ── */
$url_check_in  = trim($_GET['check_in']  ?? '');
$url_check_out = trim($_GET['check_out'] ?? '');
$has_dates     = ($url_check_in !== '' && $url_check_out !== '');

// Prefer the split adults/children params (new links). Fall back to the
// old combined "guests" param (older links / bookmarks) treating it as
// all-adults so nothing breaks for links generated before this change.
if (isset($_GET['adults']) || isset($_GET['child'])) {
    $url_adults   = max(1, intval($_GET['adults']   ?? 1));
    $url_child = max(0, intval($_GET['child'] ?? 0));
} else {
    $url_adults   = max(1, intval($_GET['guests'] ?? 1));
    $url_child = 0;
}
$url_guests = $url_adults + $url_child; // total, used for capacity/availability checks
$url_rooms  = max(1, intval($_GET['rooms'] ?? 1));

/* ── Helpers ── */
function fmtDisplay($d) { return (new DateTime($d))->format('M j, Y'); }
function diffNights($ci, $co) { return max(1, (new DateTime($ci))->diff(new DateTime($co))->days); }

/* ── Room data: DATABASE is the source of truth for everything now,
   including description, image, capacity, badge, and tags — all set
   directly from the admin panel's Add/Edit Room form, so any room
   added or edited there shows up here automatically, no code changes
   needed. Defaults below only apply to rooms missing a value (e.g.
   added before these columns existed). ── */
$default_img  = '../assets/images/standard_room.jpg';
$default_desc = 'A comfortable accommodation at CoraVergel Resort. Contact us for more details about this room.';
$default_meta = [
    'cap'   => 4,
    'badge' => 'Available',
    'tags'  => ['Free Entrance'],
];

$rooms = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image, capacity, badge, tags FROM rooms ORDER BY room_name");
if ($rq) {
    while ($row = $rq->fetch_assoc()) {
        // Use the room's actual saved capacity/badge/tags when present;
        // only fall back to defaults for rooms that were added before
        // these columns existed or were left blank.
        $tags = !empty($row['tags'])
            ? array_map('trim', explode(',', $row['tags']))
            : $default_meta['tags'];

        $rooms[] = [
            'id'          => $row['room_name'],
            'room_id'     => $row['room_id'],
            'price'       => (float) $row['price'],
            'total_units' => (int) $row['total_units'],
            'desc'        => !empty($row['description']) ? $row['description'] : $default_desc,
            'img'         => !empty($row['image']) ? '../assets/images/rooms/' . $row['image'] : $default_img,
            'cap'         => !empty($row['capacity']) ? (int) $row['capacity'] : $default_meta['cap'],
            'badge'       => !empty($row['badge']) ? $row['badge'] : $default_meta['badge'],
            'tags'        => $tags,
        ];
    }
}

/* ── Booking POST ── */
$booking_success = '';
$booking_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_room') {
    $room_type      = htmlspecialchars(strip_tags(trim($_POST['room_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $check_in       = trim($_POST['check_in'] ?? '');
    $check_out      = trim($_POST['check_out'] ?? '');
    $guests         = intval($_POST['guests'] ?? 0);
    $adults         = max(1, intval($_POST['adults'] ?? $guests ?: 1));
    $children       = max(0, intval($_POST['children'] ?? 0));
    $guest_name     = htmlspecialchars(strip_tags(trim($_POST['guest_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $guest_email    = htmlspecialchars(strip_tags(trim($_POST['guest_email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $id_type        = htmlspecialchars(strip_tags(trim($_POST['id_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $contact_number = htmlspecialchars(strip_tags(trim($_POST['contact_number'] ?? '')), ENT_QUOTES, 'UTF-8');
    $payment_method = htmlspecialchars(strip_tags(trim($_POST['payment_method'] ?? '')), ENT_QUOTES, 'UTF-8');
    $payment_ref    = htmlspecialchars(strip_tags(trim($_POST['payment_reference'] ?? '')), ENT_QUOTES, 'UTF-8');
    $allowed_payment_methods = ['Cash', 'E-wallet', 'Bank Transfer'];
    $id_photo       = '';

    if (empty($room_type) || empty($check_in) || empty($check_out) || $guests < 1) {
        $booking_error = "Missing booking details. Please try again.";
    } elseif (empty($guest_name) || empty($guest_email) || empty($id_type) || empty($contact_number)) {
        $booking_error = "Please fill in your personal details, email, valid ID, and contact number to confirm your booking.";
    } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
        $booking_error = "Please enter a valid email address.";
    } elseif (!in_array($payment_method, $allowed_payment_methods, true)) {
        $booking_error = "Please select a payment method.";
    } elseif (empty($_FILES['id_photo']['name']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
        $booking_error = "Please upload a photo of your valid ID.";
    } elseif ($check_in < date('Y-m-d')) {
        $booking_error = "Check-in date cannot be in the past.";
    } elseif ($check_in >= $check_out) {
        $booking_error = "Check-out must be after check-in.";
    } elseif (!isRoomAvailable($conn, $room_type, $check_in, $check_out)) {
        $booking_error = "Sorry, " . htmlspecialchars($room_type) . " is fully booked for the selected dates. Please choose different dates or another room.";
    } else {
        /* ── Handle ID photo upload ── */
        $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
        $max_bytes    = 5 * 1024 * 1024; // 5MB

        $tmp_name  = $_FILES['id_photo']['tmp_name'];
        $orig_name = $_FILES['id_photo']['name'];
        $file_size = $_FILES['id_photo']['size'];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $mime      = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';

        if (!in_array($ext, $allowed_ext, true) || ($mime && !in_array($mime, $allowed_mime, true))) {
            $booking_error = "ID photo must be a JPG, PNG, or WEBP image.";
        } elseif ($file_size > $max_bytes) {
            $booking_error = "ID photo must be smaller than 5MB.";
        } else {
            $upload_dir = '../assets/uploads/ids/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $id_photo = uniqid('id_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp_name, $upload_dir . $id_photo)) {
                $booking_error = "Something went wrong uploading your ID photo. Please try again.";
                $id_photo = '';
            }
        }

        if (empty($booking_error)) {
            // Look up the room's nightly price from the $rooms array so we can
            // store a total at booking time (used by admin dashboard revenue stats).
            $price_per_night = 0;
            foreach ($rooms as $rm) {
                if ($rm['id'] === $room_type) { $price_per_night = $rm['price']; break; }
            }
            $nights = diffNights($check_in, $check_out);
            $total_price = $price_per_night * $nights;

            $stmt = $conn->prepare("INSERT INTO bookings (room_type, check_in, check_out, guests, adults, children, total_price, guest_name, guest_email, id_type, id_photo, contact_number, payment_method, payment_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
    "sssiiidsssssss",
    $room_type,
    $check_in,
    $check_out,
    $guests,
    $adults,
    $children,
    $total_price,
    $guest_name,
    $guest_email,
    $id_type,
    $id_photo,
    $contact_number,
    $payment_method,
    $payment_ref
);
            if ($stmt->execute()) {
                $booking_success = "Your booking for <strong>" . htmlspecialchars($room_type) . "</strong> has been submitted! We'll confirm it shortly — please keep your email and phone reachable.";
            } else {
                $booking_error = "Something went wrong. Please try again.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms &amp; Rates</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/user.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .payment-info-box {
            font-size: 0.82rem;
            line-height: 1.5;
            background: #f7f4ee;
            border: 1px solid #e6dfd0;
            border-radius: 8px;
            padding: 10px 12px;
            color: #6b5a34;
        }

        /* Compact guests flyout (rooms/adults/children counters) */
        .guests-flyout-container .g-row {
            padding: 6px 0;
        }
        .guests-flyout-container .g-lbl {
            font-size: 0.78rem;
        }
        .guests-flyout-container .g-sub {
            font-size: 0.66rem;
        }
        .guests-flyout-container .g-counter {
            gap: 6px;
        }
        .guests-flyout-container .g-counter button {
            width: 28px;
            height: 28px;
            font-size: 0.75rem;
            line-height: 1;
        }
        .guests-flyout-container .g-counter span {
            font-size: 0.78rem;
            min-width: 18px;
            text-align: center;
        }
    </style>
    </head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">

    <!-- LEFT: hamburger (mobile) / nav links (desktop) -->
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">
        <button class="nav-hamburger" id="navHamburger" onclick="openDrawer()" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <!-- Desktop links -->
        <div class="nav-links">
            <a href="about.php">about</a>
            <a href="rooms.php" class="active-link">rooms &amp; rates</a>
            <a href="gallery.php">gallery</a>
            <a href="deals.php">deals</a>
            <a href="index.php#contact">contact</a>
        </div>
    </div>

    <a href="index.php" class="navbar-brand">
        <div class="custom-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>

    <!-- No accounts on this site — nav-login intentionally removed.
         Re-add a profile/login icon here later if accounts come back. -->
    <div class="nav-login"></div>

</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>


<!-- Slide Drawer -->
<div class="nav-drawer" id="navDrawer">

    <!-- Nav links -->
    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php" class="active-link">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
        <a href="gallery.php">Gallery <i class="fa-solid fa-chevron-right"></i></a>
        <a href="deals.php">Deals <i class="fa-solid fa-chevron-right"></i></a>
        <a href="index.php#contact">Contact <i class="fa-solid fa-chevron-right"></i></a>
    </nav>

    <!-- Footer branding -->
    <div class="drawer-footer">
        <div class="drawer-footer-eyebrow">Resort Tigbauan, Iloilo</div>
        <div class="drawer-footer-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel">
            <span class="drawer-footer-name">CoraVergel Resort</span>
        </div>
    </div>

</div>
</div>
<button class="drawer-close-x" id="drawerCloseBtn" onclick="closeDrawer()" aria-label="Close menu">
    <i class="fa-solid fa-xmark"></i>
</button>

<!-- ALERTS -->
<?php if ($booking_success): ?>
<div class="page-alert page-alert--success" id="pageAlert">
    <i class="fa-solid fa-circle-check"></i>
    <div>
        <?= $booking_success ?>
    </div>
    <button onclick="this.parentElement.remove()" class="alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>
<?php if ($booking_error): ?>
<div class="page-alert page-alert--error" id="pageAlert">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><?= htmlspecialchars($booking_error) ?></div>
    <button onclick="this.parentElement.remove()" class="alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<!-- BOOKING MODAL -->
<div class="modal-overlay" id="bookModal">
    <div class="modal-box">
        <div class="modal-photo-wrap">
            <img class="modal-room-img-preview" id="modalImg" src="../assets/images/standard_room.jpg" alt="">
            <div class="modal-photo-caption">
                <span id="modalRoomName">Room Name</span>
                <span class="modal-price" id="modalRoomPrice">₱0/night</span>
            </div>
        </div>

        <?php if ($has_dates): ?>
        <form class="modal-form modal-right" method="POST" enctype="multipart/form-data"
              action="rooms.php?<?= http_build_query(['check_in'=>$url_check_in,'check_out'=>$url_check_out,'guests'=>$url_guests]) ?>"
              id="bookForm">
            <input type="hidden" name="action"    value="book_room">
            <input type="hidden" name="room_type" id="formRoomType" value="">
            <input type="hidden" name="check_in"  value="<?= htmlspecialchars($url_check_in) ?>">
            <input type="hidden" name="check_out" value="<?= htmlspecialchars($url_check_out) ?>">
            <input type="hidden" name="guests"    id="formGuests"   value="<?= $url_guests ?>">
            <input type="hidden" name="adults"    id="formAdults"   value="<?= $url_adults ?>">
            <input type="hidden" name="children"  id="formChildren" value="<?= $url_child ?>">
            <input type="hidden" name="rooms"     id="formRooms"    value="<?= $url_rooms ?>">

            <div class="modal-scroll">
                <div class="modal-summary-grid">
                    <div class="ms-chip">
                        <div class="ms-chip-lbl">Check-in</div>
                        <div class="ms-chip-val"><?= fmtDisplay($url_check_in) ?></div>
                    </div>
                    <div class="ms-chip">
                        <div class="ms-chip-lbl">Check-out</div>
                        <div class="ms-chip-val"><?= fmtDisplay($url_check_out) ?></div>
                    </div>
                    <div class="ms-chip">
                        <div class="ms-chip-lbl">Duration</div>
                        <div class="ms-chip-val"><?= diffNights($url_check_in,$url_check_out) ?> Night<?= diffNights($url_check_in,$url_check_out)!==1?'s':'' ?></div>
                    </div>
                    <div class="ms-chip">
                        <div class="ms-chip-lbl">Guests</div>
                        <div class="ms-chip-val"><?= $url_guests ?> Guest<?= $url_guests!==1?'s':'' ?></div>
                    </div>
                </div>

                <div class="modal-section-lbl">Guest details</div>

                <div class="cf-field">
                    <label for="guestName">Full name</label>
                    <input type="text" id="guestName" name="guest_name" required placeholder="Fullname">
                </div>
                <div class="cf-field">
                    <label for="guestEmail">Email address</label>
                    <input type="email" id="guestEmail" name="guest_email" required placeholder="Email">
                </div>
                <div class="cf-field">
                    <label for="idType">Valid ID type</label>
                    <select id="idType" name="id_type" required onchange="handleIdTypeChange()">
                        <option value="">Select ID type</option>
                        <option value="Government ID">Government ID</option>
                        <option value="Driver's License">Driver's License</option>
                        <option value="Passport">Passport</option>
                        <option value="School ID">School ID</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="cf-field" id="idPhotoField" style="display:none;">
                    <label for="idPhotoInput">Upload photo of your ID</label>
                    <label class="id-upload-wrap" id="idUploadWrap" for="idPhotoInput">
                        <i class="fa-solid fa-camera"></i>
                        <div class="id-upload-label" id="idUploadLabel">Tap to upload a photo of your ID</div>
                        <div class="id-upload-hint">JPG, PNG, or WEBP · Max 5MB</div>
                    </label>
                    <input type="file" id="idPhotoInput" name="id_photo" accept=".jpg,.jpeg,.png,.webp,image/*" onchange="handleIdPhotoChange()">
                </div>
                <div class="cf-field">
                    <label for="contactNumber">Contact number</label>
                    <input type="tel" id="contactNumber" name="contact_number" required placeholder="09XX XXX XXXX" pattern="^[0-9+\-\s]{7,15}$">
                </div>


                <div class="cf-field">
                    <label for="paymentMethod">Payment method</label>
                    <select id="paymentMethod" name="payment_method" required onchange="handlePaymentMethodChange()">
                        <option value="">Select payment method</option>
                        <option value="Cash">Cash</option>
                        <option value="E-wallet">E-wallet</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>

                <div class="cf-field" id="paymentInfoField" style="display:none;">
                    <div class="payment-info-box" id="paymentInfoBox"></div>
                </div>

                <div class="cf-field" id="paymentRefField" style="display:none;">
                    <label for="paymentReference">Reference / transaction number</label>
                    <input type="text" id="paymentReference" name="payment_reference" placeholder="e.g. GCash ref number">
                </div>
            </div>

            <div class="modal-footer-bar">
                <div>
                    <div class="mf-total-lbl">Total estimate</div>
                    <div class="mf-total-val" id="modalTotal">₱0</div>
                </div>
                <button type="submit" class="modal-submit">
                    Confirm booking
                </button>
            </div>
        </form>

        <?php else: ?>
        <div class="modal-right">
            <div class="modal-scroll">
                <div class="modal-no-dates">
                    <i class="fa-regular fa-calendar-days"></i>
                    <p class="mnd-title">No dates selected yet</p>
                    <p class="mnd-sub">Pick your check-in and check-out dates first, then come back to book.</p>
                    <a href="dashboard.php#booking-section" class="modal-submit" style="margin-top:8px;">
                        <i class="fa-solid fa-calendar-days"></i> Pick dates
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<button class="modal-close" id="modalCloseBtn" onclick="closeModal()" aria-label="Close">
    <i class="fa-solid fa-xmark"></i>
</button>

<!-- ROOM PREVIEW LIGHTBOX -->
<div class="room-lightbox" id="roomLightbox" onclick="closeRoomLightbox(event)">
    <button class="room-lightbox-close" onclick="closeRoomLightbox(event)" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <div class="room-lightbox-img-wrap" onclick="event.stopPropagation()">
        <img id="roomLightboxImg" src="" alt="">
        <div class="room-lightbox-caption" id="roomLightboxCaption"></div>
    </div>
</div>

<!-- RESORT HERO -->
<div class="resort-hero">
    <img class="resort-hero-img" src="../assets/images/background.jpg" alt="CoraVergel Resort"
         onerror="this.src='https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?w=1600&q=80'">
    <div class="resort-hero-overlay"></div>
    <div class="resort-hero-info">
        <div class="resort-hero-name">CoraVergel Resort</div>
        <div class="resort-hero-details">
            <a class="resort-hero-detail" href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-building"></i> 21 Barosong, Tigbauan, Iloilo City, Philippines
            </a>
            <a class="resort-hero-detail" href="tel:3202512">
                <i class="fa-solid fa-phone"></i> +320 2512
            </a>
            <a class="resort-hero-detail" href="https://www.facebook.com/coravergelresort" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-link"></i> facebook.com/coravergelresort
            </a>
        </div>
    </div>
</div>

<!-- PAGE BODY -->
<div class="page-body">

    <!-- ══ OVERNIGHT ROOMS ══ -->
    <section class="content-section">

        <!-- STICKY DATE BAR — only shown once the user already has dates selected -->
        <?php if ($has_dates): ?>
        <div class="search-bar-container_inner" id="dateBar">

            <div aria-live="assertive" aria-relevant="all" class="sr-only" id="dbLiveRegion"></div>

            <div class="search-bar-container_top">

                <!-- Guests -->
                <div class="search-bar-container_guestsWrapper">
                    <button type="button"
                            class="search-bar-container_guests"
                            aria-label="Guests, <?= $url_rooms ?> room<?= $url_rooms !== 1 ? 's' : '' ?>, <?= $url_adults ?> adult<?= $url_adults !== 1 ? 's' : '' ?>, <?= $url_child ?> child"
                            aria-expanded="false"
                            aria-controls="guestsFlyoutContainer"
                            onclick="toggleDbGuests(event)"
                            id="dbGuestBox">
                        <i class="fa-solid fa-user search-bar-icon-left" aria-hidden="true"></i>
                        <div class="search-bar-container_text">
                            <span class="search-bar-container_label">Guests</span>
                            <span id="dbGuestVal"><?= $url_rooms ?> room<?= $url_rooms !== 1 ? 's' : '' ?>, <?= $url_adults ?> adult<?= $url_adults !== 1 ? 's' : '' ?>, <?= $url_child ?> child</span>
                        </div>
                    </button>

                    <div class="guests-flyout-container" id="guestsFlyoutContainer" onclick="event.stopPropagation()">
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Rooms</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjRoom(-1)" aria-label="Decrease rooms">−</button>
                                <span id="gRoomCount"><?= $url_rooms ?></span>
                                <button type="button" onclick="gAdjRoom(1)" aria-label="Increase rooms">+</button>
                            </div>
                        </div>
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Adults</div>
                                <div class="g-sub">Ages 18+</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjAdult(-1)" aria-label="Decrease adults">−</button>
                                <span id="gAdultCount"><?= $url_adults ?></span>
                                <button type="button" onclick="gAdjAdult(1)" aria-label="Increase adults">+</button>
                            </div>
                        </div>
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Child</div>
                                <div class="g-sub">Ages 0–17</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjChild(-1)" aria-label="Decrease child">−</button>
                                <span id="gChildCount"><?= $url_child ?></span>
                                <button type="button" onclick="gAdjChild(1)" aria-label="Increase child">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Check-in -->
                <button type="button"
                        class="search-bar-container_checkIn"
                        aria-label="Check-in <?= $has_dates ? fmtDisplay($url_check_in) : 'not selected' ?>"
                        aria-expanded="false"
                        aria-controls="calendarFlyoutContainer"
                        onclick="toggleDbCal(event)"
                        id="dbCheckInBox">
                    <i class="fa-regular fa-calendar-days search-bar-icon-left" aria-hidden="true"></i>
                    <div class="search-bar-container_text">
                        <span class="search-bar-container_label">Check-in</span>
                        <span id="dbCheckInVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                            <?= $has_dates ? fmtDisplay($url_check_in) : 'Select date' ?>
                        </span>
                    </div>
                </button>

                <!-- Check-out -->
                <button type="button"
                        class="search-bar-container_checkOut"
                        aria-label="Check-out <?= $has_dates ? fmtDisplay($url_check_out) : 'not selected' ?>"
                        aria-expanded="false"
                        aria-controls="calendarFlyoutContainer"
                        onclick="toggleDbCal(event)"
                        id="dbCheckOutBox">
                    <i class="fa-regular fa-calendar-days search-bar-icon-left" aria-hidden="true"></i>
                    <div class="search-bar-container_text">
                        <span class="search-bar-container_label">Check-out</span>
                        <span id="dbCheckOutVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                            <?= $has_dates ? fmtDisplay($url_check_out) : 'Select date' ?>
                        </span>
                    </div>
                </button>

                <!-- Shared calendar flyout -->
                <div class="calendar-flyout-container" id="calendarFlyoutContainer" onclick="event.stopPropagation()">

                    <div class="cal-summary-row">
                        <div class="cal-summary-item">
                            <div class="cal-summary-lbl">Check-in</div>
                            <div class="cal-summary-val <?= !$has_dates ? 'empty' : '' ?>" id="calFromVal">
                                <?= $has_dates ? fmtDisplay($url_check_in) : 'Select' ?>
                            </div>
                        </div>
                        <div class="cal-summary-item">
                            <div class="cal-summary-lbl">Check-out</div>
                            <div class="cal-summary-val <?= !$has_dates ? 'empty' : '' ?>" id="calToVal">
                                <?= $has_dates ? fmtDisplay($url_check_out) : 'Select' ?>
                            </div>
                        </div>
                    </div>

                    <div class="cal-months-row">
                        <div class="cal-month-block">
                            <div class="cal-month-header"><span id="calMonthLabel1"></span></div>
                            <div class="cal-dow-row">
                                <div class="cal-dow">Su</div><div class="cal-dow">Mo</div><div class="cal-dow">Tu</div>
                                <div class="cal-dow">We</div><div class="cal-dow">Th</div><div class="cal-dow">Fr</div><div class="cal-dow">Sa</div>
                            </div>
                            <div class="cal-days-grid" id="calDaysGrid1"></div>
                        </div>

                        <div class="cal-month-block">
                            <div class="cal-month-header">
                                <span id="calMonthLabel2"></span>
                                <button type="button" class="cal-nav-btn" onclick="calNext()" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                            </div>
                            <div class="cal-dow-row">
                                <div class="cal-dow">Su</div><div class="cal-dow">Mo</div><div class="cal-dow">Tu</div>
                                <div class="cal-dow">We</div><div class="cal-dow">Th</div><div class="cal-dow">Fr</div><div class="cal-dow">Sa</div>
                            </div>
                            <div class="cal-days-grid" id="calDaysGrid2"></div>
                        </div>
                    </div>

                    <div class="cal-footer">
                        <span class="cal-summary" id="calSummary"></span>
                        <button type="button" class="cal-clear" onclick="calClear()">Cancel</button>
                        <button type="button" class="cal-done" onclick="calDone()">Search</button>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <?php
        /* ── Pre-check: are ANY rooms bookable for the selected dates/guests? ──
           Reuses the same availability logic as the room cards below, just
           run early so we know whether to show the "not available" alert. */
        $no_rooms_available = false;
        if ($has_dates && !empty($rooms)) {
            $bookable_count = 0;
            foreach ($rooms as $r) {
                $chk_over_cap = $url_guests > $r['cap'];
                $chk_units    = getAvailableUnits($conn, $r['id'], $url_check_in, $url_check_out);
                if (!$chk_over_cap && $chk_units > 0) $bookable_count++;
            }
            $no_rooms_available = ($bookable_count === 0);
        }
        ?>

        <div class="section-label">
            <h2 class="section-heading">Accommodations</h2>
        </div>

        <?php if ($no_rooms_available): ?>
        <div class="unavailable-alert">
            <div class="unavailable-alert-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="unavailable-alert-text">
                <p class="unavailable-alert-title">
                    No rooms available for <?= fmtDisplay($url_check_in) ?> &ndash; <?= fmtDisplay($url_check_out) ?>.
                </p>
                <p class="unavailable-alert-sub">
                    Please try choosing different dates, a shorter stay, or another guest count.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div class="rooms-grid">
            <?php if (empty($rooms)): ?>
            <p style="grid-column:1/-1;text-align:center;color:#999;">Rooms are being updated. Please check back shortly.</p>
            <?php endif; ?>
            <?php
            foreach ($rooms as $r):
                $over_cap = $has_dates && $url_guests > $r['cap'];
                $nights   = $has_dates ? diffNights($url_check_in, $url_check_out) : null;
                $total    = $nights ? $r['price'] * $nights : null;
                $available_units = $has_dates ? getAvailableUnits($conn, $r['id'], $url_check_in, $url_check_out) : null;
                $fully_booked = $has_dates && $available_units !== null && $available_units <= 0;
            ?>
            <div class="room-card <?= ($over_cap || $fully_booked) ? 'room-card--dimmed' : '' ?>">
                <div class="room-img" onclick="openRoomLightbox('<?= addslashes($r['id']) ?>', '<?= addslashes($r['img']) ?>')">
                    <img src="<?= htmlspecialchars($r['img']) ?>" alt="<?= htmlspecialchars($r['id']) ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
                    <?php if ($fully_booked): ?>
                    <span class="room-badge room-badge--full">Fully Booked</span>
                    <?php else: ?>
                    <span class="room-badge"><?= htmlspecialchars($r['badge']) ?></span>
                    <?php endif; ?>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> <?= $r['cap'] ?> pax</span>
                    <span class="room-zoom-hint"><i class="fa-solid fa-expand"></i></span>
                    <?php if ($over_cap): ?>
                    <div class="room-over-cap"><i class="fa-solid fa-users-slash"></i> Exceeds capacity</div>
                    <?php elseif ($fully_booked): ?>
                    <div class="room-over-cap"><i class="fa-solid fa-ban"></i> Fully booked these dates</div>
                    <?php endif; ?>
                </div>
                <div class="room-body">
                    <h3 class="room-name"><?= htmlspecialchars($r['id']) ?></h3>
                    <p class="room-desc"><?= $r['desc'] ?></p>
                    <div class="room-tags">
                        <?php foreach ($r['tags'] as $t): ?>
                        <span class="room-tag"><?= htmlspecialchars($t) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($has_dates && !$fully_booked && !$over_cap && $available_units !== null && $available_units <= 2): ?>
                    <div class="room-low-stock" style="font-size:12px;color:#c62828;font-weight:600;margin-bottom:6px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Only <?= $available_units ?> unit<?= $available_units!=1?'s':'' ?> left for these dates
                    </div>
                    <?php endif; ?>
                    <div class="room-footer">
                        <div class="room-price">
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt"><?= number_format($r['price']) ?></span>
                            <span class="rp-per">/night</span>
                            <?php if ($total): ?>
                            <span class="rp-total">₱<?= number_format($total) ?> total</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($over_cap): ?>
                        <div class="btn-book--disabled">
                            <i class="fa-solid fa-users-slash"></i> Over Capacity
                        </div>
                        <?php elseif ($fully_booked): ?>
                        <div class="btn-book--disabled">
                            <i class="fa-solid fa-ban"></i> Fully Booked
                        </div>
                        <?php else: ?>
                        <button class="btn-book"
                            onclick="openModal('<?= addslashes($r['id']) ?>', <?= $r['price'] ?>, '<?= addslashes($r['img']) ?>')">
                            Book Now
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ COTTAGES ══ -->
    <section class="content-section content-section--alt">
        <div class="section-inner">
            <div class="section-label">
                <span class="label-pill label-pill--gold"><i class="fa-solid fa-sun"></i> Day Use</span>
                <h2 class="section-heading">Cottages &amp; Gazebos</h2>
            </div>
            <div class="small-cards-grid">
                <?php
                $cottages = [
                    ['Large Gazebo','15 pax','1,500','fa-umbrella-beach','Near swimming pool'],
                    ['Small Gazebo','8 pax', '1,200','fa-umbrella-beach','Near swimming pool'],
                    ['Umbrella',    '4 pax', '400',  'fa-umbrella',      'Open area'],
                    ['Small Kubo',  '10 pax','1,000','fa-house',         'Shaded cottage'],
                    ['Large Kubo',  '20 pax','2,000','fa-house',         'Great for big groups'],
                ];
                foreach ($cottages as $c): ?>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid <?= $c[3] ?>"></i></div>
                    <div>
                        <div class="sc-name"><?= $c[0] ?></div>
                        <div class="sc-sub"><?= $c[4] ?></div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to <?= $c[1] ?></div>
                    </div>
                    <div class="sc-price">
                        <span class="scp-sym">₱</span><span class="scp-amt"><?= $c[2] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══ TENTS ══ -->
    <section class="content-section">
        <div class="section-label">
            <span class="label-pill label-pill--dark"><i class="fa-solid fa-campground"></i> Tent Rental</span>
            <h2 class="section-heading">Premium Tents</h2>
            <p class="section-sub">Check-in 5PM · Check-out 7AM · Includes mattress, pillows &amp; blankets · Free Entrance</p>
        </div>
        <div class="tent-cards-grid">
            <?php
            $tents = [
                ['Premium Tent A','2 pax','900',  '6 units available'],
                ['Premium Tent B','3 pax','1,100','1 unit available'],
                ['Premium Tent C','6 pax','2,300','1 unit available'],
            ];
            foreach ($tents as $t): ?>
            <div class="tent-card">
                <div class="tent-icon"><i class="fa-solid fa-campground"></i></div>
                <div class="tent-body">
                    <div class="tent-name"><?= $t[0] ?></div>
                    <div class="tent-meta">
                        <i class="fa-solid fa-user-group"></i> <?= $t[1] ?>
                        <span class="tent-sep">·</span> <?= $t[3] ?>
                    </div>
                    <div class="tent-includes">
                        <i class="fa-solid fa-check"></i> Free Entrance
                        <span class="tent-sep">·</span>
                        <i class="fa-solid fa-check"></i> Bedding Included
                    </div>
                </div>
                <div class="tent-price">
                    <span class="tp-sym">₱</span><span class="tp-amt"><?= $t[2] ?></span>
                    <span class="tp-night">/night</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ REMINDERS ══ -->
    <section class="content-section">
        <div class="reminders-card">
            <div class="rem-head">
                <i class="fa-solid fa-circle-info"></i>
                <h3>Resort Reminders</h3>
            </div>
            <div class="rem-grid">
                <div class="rem-item"><i class="fa-solid fa-clock"></i> Check-in: 2:00 PM · Check-out: 12:00 PM</div>
                <div class="rem-item"><i class="fa-solid fa-ban"></i> No outside food &amp; beverages allowed</div>
                <div class="rem-item"><i class="fa-solid fa-glass-water"></i> Resort food &amp; drinks available on-site</div>
                <div class="rem-item"><i class="fa-solid fa-person-swimming"></i> Free swimming included for overnight guests</div>
                <div class="rem-item"><i class="fa-solid fa-paw"></i> No pets allowed inside the resort</div>
                <div class="rem-item"><i class="fa-solid fa-music"></i> Quiet hours: 10:00 PM – 6:00 AM</div>
            </div>
        </div>
    </section>

</div><!-- /page-body -->

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-top">
        <div class="footer-brand">
            <div class="footer-logo-wrap">
                <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort Logo" class="footer-logo-img">
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-socials">
                <a href="https://www.facebook.com/coravergelresort" aria-label="Facebook" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-facebook-f"></i></a>
                <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                <a href="#" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-links">
        <div class="footer-col">
            <h4>About</h4>
            <a href="about.php">About CoraVergel</a>
            <a href="#">Careers</a>
            <a href="#contact" onclick="smoothScroll(event,'contact')">Contact Us</a>
        </div>
        <div class="footer-col">
            <h4>Stay</h4>
            <a href="rooms.php">Duplex Rooms</a>
            <a href="rooms.php">Family Rooms</a>
            <a href="rooms.php">Small Bahay Kubo</a>
            <a href="rooms.php">Large Bahay Kubo</a>
        </div>
        <div class="footer-col">
            <h4>Offers</h4>
            <a href="special_offers.php">Special Offers</a>
            <a href="special_offers.php">Seasonal Deals</a>
            <a href="special_offers.php">Stay &amp; Dine</a>
            <a href="reviews.php">Guest Reviews</a>
        </div>
        <div class="footer-col footer-contact-col">
            <h4>Contact Information</h4>
            <a href="tel:320 2512" class="topbar-link">+320 2512</a>
            <a href="mailto:coravergelresort@gmail.com" class="topbar-link">coravergelresort@gmail.com</a>
            <br>
            <h4>Address</h4>
            <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link">21 Barosong, Tigbauan,<br>Iloilo City, Philippines</a>
            <div class="footer-map-icons">
                <a class="fa-solid fa-location-dot" href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" title="View on Google Maps"></a>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <span>&copy; CoraVergel Resort. All rights reserved.</span>
        <div class="footer-bottom-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Use</a>
            <a href="#">Cookie Policy</a>
        </div>
    </div>
</footer>

<script>
/* ══════════════════════════════════════
   PHP → JS
══════════════════════════════════════ */
const HAS_DATES  = <?= $has_dates ? 'true' : 'false' ?>;
const URL_CI     = "<?= addslashes($url_check_in) ?>";
const URL_CO     = "<?= addslashes($url_check_out) ?>";
const URL_GUESTS = <?= $url_guests ?>;
const URL_ADULTS   = <?= $url_adults ?>;
const URL_CHILDREN = <?= $url_child ?>;
const URL_ROOMS     = <?= $url_rooms ?>;

/* Room price/image lookup — generated straight from the DB-driven
   $rooms array, so it always matches whatever's in the admin panel. */
const ROOM_MAP = <?= json_encode(array_combine(
    array_column($rooms, 'id'),
    array_map(fn($r) => ['price' => $r['price'], 'img' => $r['img']], $rooms)
)) ?>;

/* ══════════════════════════════════════
   DATE BAR CALENDAR
══════════════════════════════════════ */
const MF = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const MS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

let calY, calM, calStart = null, calEnd = null;

(function(){
    const now = new Date();
    if (HAS_DATES) {
        calStart = new Date(URL_CI + 'T00:00:00');
        calEnd   = new Date(URL_CO + 'T00:00:00');
        calY = calStart.getFullYear(); calM = calStart.getMonth();
    } else {
        calY = now.getFullYear(); calM = now.getMonth();
    }
})();

function dStr(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function dDisp(d){ return MS[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }

function announce(msg){
    const el = document.getElementById('dbLiveRegion');
    el.textContent = '';
    setTimeout(() => { el.textContent = msg; }, 50);
}

function toggleDbCal(e){
    e.stopPropagation();
    closeGuests();
    const flyout = document.getElementById('calendarFlyoutContainer');
    const inBox  = document.getElementById('dbCheckInBox');
    const outBox = document.getElementById('dbCheckOutBox');
    const open   = flyout.classList.toggle('open');
    inBox.setAttribute('aria-expanded', open);
    outBox.setAttribute('aria-expanded', open);
    if (open) renderCal();
}

function closeCal(){
    document.getElementById('calendarFlyoutContainer').classList.remove('open');
    document.getElementById('dbCheckInBox').setAttribute('aria-expanded', 'false');
    document.getElementById('dbCheckOutBox').setAttribute('aria-expanded', 'false');
}

function buildMonthGrid(y, m, gridId){
    const first = new Date(y,m,1).getDay();
    const days  = new Date(y,m+1,0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);
    let h='';
    for(let i=0;i<first;i++) h+=`<button type="button" class="cal-day cal-empty" disabled></button>`;
    for(let d=1;d<=days;d++){
        const dt = new Date(y,m,d);
        let cls = 'cal-day';
        const isPast = dt < today;
        if(isPast) cls += ' cal-disabled';
        if(dt.toDateString()===today.toDateString()) cls += ' cal-today';
        if(calStart && calEnd){
            const t=dt.getTime(), s=calStart.getTime(), e=calEnd.getTime();
            if(t===s && t===e) cls += ' cal-start cal-end';
            else if(t===s)      cls += ' cal-start';
            else if(t===e)      cls += ' cal-end';
            else if(t>s && t<e) cls += ' cal-in-range';
        } else if(calStart && dt.toDateString()===calStart.toDateString()){
            cls += ' cal-start cal-end';
        }
        h += `<button type="button" class="${cls}" ${isPast?'disabled':''} onclick="calPick(${y},${m},${d})">${d}</button>`;
    }
    document.getElementById(gridId).innerHTML = h;
}

function renderCal(){
    document.getElementById('calMonthLabel1').textContent = MF[calM] + ' ' + calY;
    let m2 = calM + 1, y2 = calY;
    if (m2 > 11) { m2 = 0; y2++; }
    document.getElementById('calMonthLabel2').textContent = MF[m2] + ' ' + y2;

    buildMonthGrid(calY, calM, 'calDaysGrid1');
    buildMonthGrid(y2, m2, 'calDaysGrid2');

    const fEl = document.getElementById('calFromVal'), tEl = document.getElementById('calToVal');
    fEl.textContent = calStart ? dDisp(calStart) : 'Select';
    fEl.className   = 'cal-summary-val' + (calStart ? '' : ' empty');
    tEl.textContent = calEnd ? dDisp(calEnd) : 'Select';
    tEl.className   = 'cal-summary-val' + (calEnd ? '' : ' empty');

    const s = document.getElementById('calSummary');
    if (calStart && calEnd) {
        const n = Math.round((calEnd - calStart) / 86400000);
        s.textContent = n + ' night' + (n !== 1 ? 's' : '') + ' selected';
    } else if (calStart) {
        s.textContent = 'Now pick check-out';
    } else {
        s.textContent = '';
    }
}

function calNext(){ calM++; if(calM>11){calM=0;calY++;} renderCal(); }

function calPick(y,m,d){
    const dt = new Date(y,m,d);
    const today = new Date(); today.setHours(0,0,0,0);
    if (dt < today) return;
    if (!calStart || (calStart && calEnd)) { calStart = dt; calEnd = null; announce('Check-in ' + dDisp(dt) + ' selected'); }
    else {
        if (dt <= calStart) { calStart = dt; calEnd = null; announce('Check-in ' + dDisp(dt) + ' selected'); }
        else { calEnd = dt; announce('Check-out ' + dDisp(dt) + ' selected'); }
    }
    renderCal();
}

function calDone(){
    if(!calStart || !calEnd) return;
    const p = new URLSearchParams({
        check_in: dStr(calStart),
        check_out: dStr(calEnd),
        guests: adultCount + childCount,
        adults: adultCount,
        child: childCount,
        rooms: roomCount
    });
    window.location.href = 'rooms.php?' + p.toString();
}

function calClear(){
    calStart = null; calEnd = null;
    document.getElementById('dbCheckInVal').textContent = 'Select date';
    document.getElementById('dbCheckInVal').classList.add('is-placeholder');
    document.getElementById('dbCheckOutVal').textContent = 'Select date';
    document.getElementById('dbCheckOutVal').classList.add('is-placeholder');
    closeCal();
}

/* ── Guests ── */
function closeGuests(){
    document.getElementById('guestsFlyoutContainer').classList.remove('open');
    document.getElementById('dbGuestBox').setAttribute('aria-expanded', 'false');
}
function toggleDbGuests(e){
    e.stopPropagation();
    closeCal();
    const flyout = document.getElementById('guestsFlyoutContainer');
    const box    = document.getElementById('dbGuestBox');
    const open   = flyout.classList.toggle('open');
    box.setAttribute('aria-expanded', open);
}
let roomCount = URL_ROOMS, adultCount = URL_ADULTS, childCount = URL_CHILDREN;

function gUpdateLabel(){
    const txt = roomCount + ' room' + (roomCount !== 1 ? 's' : '') + ', ' +
                adultCount + ' adult' + (adultCount !== 1 ? 's' : '') + ', ' +
                childCount + ' child';
    document.getElementById('dbGuestVal').textContent = txt;
    document.getElementById('dbGuestBox').setAttribute('aria-label', 'Guests, ' + txt);

    // Keep the booking form's hidden fields in sync so "Book Now" always
    // submits the counts actually shown here, not stale page-load values.
    const fGuests   = document.getElementById('formGuests');
    const fAdults   = document.getElementById('formAdults');
    const fChildren = document.getElementById('formChildren');
    const fRooms    = document.getElementById('formRooms');
    if (fGuests)   fGuests.value   = adultCount + childCount;
    if (fAdults)   fAdults.value   = adultCount;
    if (fChildren) fChildren.value = childCount;
    if (fRooms)    fRooms.value    = roomCount;
}
function gAdjRoom(d){
    roomCount = Math.max(1, roomCount + d);
    document.getElementById('gRoomCount').textContent = roomCount;
    gUpdateLabel();
    announce(roomCount + ' room' + (roomCount !== 1 ? 's' : '') + ' selected');
}
function gAdjAdult(d){
    adultCount = Math.max(1, adultCount + d);
    document.getElementById('gAdultCount').textContent = adultCount;
    gUpdateLabel();
}
function gAdjChild(d){
    childCount = Math.max(0, childCount + d);
    document.getElementById('gChildCount').textContent = childCount;
    gUpdateLabel();
}

document.addEventListener('click', function(e){
    const inBox  = document.getElementById('dbCheckInBox');
    const outBox = document.getElementById('dbCheckOutBox');
    const gBox   = document.getElementById('dbGuestBox');
    if (!inBox.contains(e.target) && !outBox.contains(e.target)) closeCal();
    if (gBox && !gBox.contains(e.target)) closeGuests();
});

/* ══════════════════════════════════════
   ID TYPE → SHOW ID PHOTO UPLOAD
══════════════════════════════════════ */
function handleIdTypeChange() {
    const idType = document.getElementById('idType').value;
    const field  = document.getElementById('idPhotoField');
    field.style.display = idType ? 'block' : 'none';
}

function handleIdPhotoChange() {
    const input = document.getElementById('idPhotoInput');
    const wrap  = document.getElementById('idUploadWrap');
    const label = document.getElementById('idUploadLabel');
    const file  = input.files[0];
    if (file) {
        wrap.classList.add('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-circle-check';
        label.innerHTML = '<span class="id-upload-filename">' + file.name + '</span><br>Tap to change photo';
    } else {
        wrap.classList.remove('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-camera';
        label.textContent = 'Tap to upload a photo of your ID';
    }
}

/* ══════════════════════════════════════
   PAYMENT METHOD
══════════════════════════════════════ */
const PAYMENT_INFO = {
    'Cash': 'Pay in cash upon check-in at the resort front desk.',
    'E-wallet': 'Send payment via GCash / Maya to <strong>+320 2512</strong>, then enter your reference number below. We\'ll verify it before confirming your booking.',
    'Bank Transfer': 'Transfer to CoraVergel Resort\'s bank account (details will be emailed to you), then enter your transaction reference number below.'
};

function handlePaymentMethodChange() {
    const selectEl  = document.getElementById('paymentMethod');
    const infoField = document.getElementById('paymentInfoField');
    const infoBox   = document.getElementById('paymentInfoBox');
    const refField  = document.getElementById('paymentRefField');
    const refInput  = document.getElementById('paymentReference');

    const val = selectEl.value;

    if (!val) {
        infoField.style.display = 'none';
        refField.style.display  = 'none';
        refInput.removeAttribute('required');
        return;
    }

    infoBox.innerHTML = PAYMENT_INFO[val] || '';
    infoField.style.display = 'block';

    if (val === 'E-wallet' || val === 'Bank Transfer') {
        refField.style.display = 'block';
        refInput.setAttribute('required', 'required');
    } else {
        refField.style.display = 'none';
        refInput.removeAttribute('required');
        refInput.value = '';
    }
}

/* ══════════════════════════════════════
   BOOKING MODAL
══════════════════════════════════════ */
let modalPrice = 0;

function openModal(roomId, price, img) {
    /* ── No dates yet: send user to dashboard to pick dates, carry room ── */
    if (!HAS_DATES) {
        window.location.href = 'dashboard.php?room=' + encodeURIComponent(roomId) + '#booking-section';
        return;
    }
    modalPrice = price;
    document.getElementById('formRoomType').value         = roomId;
    document.getElementById('modalRoomName').textContent  = roomId;
    document.getElementById('modalRoomPrice').textContent = '₱' + price.toLocaleString() + '/night';
    document.getElementById('modalImg').src               = img;
    updateTotal();

    // Reset payment method selection for a fresh booking
    const paymentSelect = document.getElementById('paymentMethod');
    if (paymentSelect) paymentSelect.value = '';
    document.getElementById('paymentInfoField').style.display = 'none';
    document.getElementById('paymentRefField').style.display  = 'none';
    const refInput = document.getElementById('paymentReference');
    if (refInput) { refInput.removeAttribute('required'); refInput.value = ''; }

    document.getElementById('bookModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    positionModalCloseBtn();
    document.getElementById('modalCloseBtn').classList.add('is-visible');
    window.addEventListener('resize', positionModalCloseBtn);
}

function closeModal(){
    document.getElementById('bookModal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('modalCloseBtn').classList.remove('is-visible');
    window.removeEventListener('resize', positionModalCloseBtn);
}

function positionModalCloseBtn(){
    const box = document.querySelector('#bookModal .modal-box');
    const btn = document.getElementById('modalCloseBtn');
    if (!box || !btn) return;

    const rect = box.getBoundingClientRect();

    // Center the button exactly on the box's top-right corner.
    // CSS transform: translate(-50%, -50%) does the rest, so half
    // the circle sits outside the border and half overlaps it.
    let top  = Math.max(19, rect.top);
    let left = Math.min(rect.right, window.innerWidth - 19);

    btn.style.top  = top + 'px';
    btn.style.left = left + 'px';
}

function updateTotal(){
    const el = document.getElementById('modalTotal');
    if (!el || !HAS_DATES) return;
    const nights = Math.round((new Date(URL_CO+'T00:00:00') - new Date(URL_CI+'T00:00:00')) / 86400000);
    if (nights > 0) el.textContent = '₱' + (modalPrice * nights).toLocaleString();
}

document.getElementById('bookModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });

/* ══════════════════════════════════════
   ROOM PREVIEW LIGHTBOX
══════════════════════════════════════ */
function openRoomLightbox(roomName, img) {
    document.getElementById('roomLightboxImg').src = img;
    document.getElementById('roomLightboxCaption').textContent = roomName;
    document.getElementById('roomLightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeRoomLightbox(e) {
    if (e) e.stopPropagation();
    document.getElementById('roomLightbox').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('roomLightbox').classList.contains('open')) {
        closeRoomLightbox();
    }
});

/* ── Auto-scroll alert ── */
<?php if ($booking_success || $booking_error): ?>
window.addEventListener('load', () => {
    const alertEl = document.getElementById('pageAlert');
    alertEl?.scrollIntoView({behavior:'smooth', block:'center'});

    // Auto-dismiss after 2 seconds
    setTimeout(() => {
        if (!alertEl) return;
        alertEl.style.transition = 'opacity .35s ease';
        alertEl.style.opacity = '0';
        setTimeout(() => alertEl.remove(), 350);
    }, 2000);
});
<?php endif; ?>

/* ── Auto-open modal if returning from dashboard with room + dates ── */
window.addEventListener('load', function() {
    <?php if ($has_dates): ?>
    const urlRoom = new URLSearchParams(window.location.search).get('room');
    if (urlRoom && ROOM_MAP[urlRoom]) {
        const r = ROOM_MAP[urlRoom];
        openModal(urlRoom, r.price, r.img);
    }
    <?php endif; ?>
});
function openDrawer() {
    document.getElementById('navDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('navHamburger').classList.add('open');
    const btn = document.getElementById('drawerCloseBtn');
    btn.style.display = 'flex';
    btn.style.position = 'fixed';
    btn.style.left = (document.getElementById('navDrawer').offsetWidth + 15) + 'px';
    document.body.style.overflow = 'hidden';
}
function closeDrawer() {
    document.getElementById('navDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('navHamburger').classList.remove('open');
    document.getElementById('drawerCloseBtn').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
    document.querySelectorAll('.nav-links a').forEach(link => {
        const linkPage = link.getAttribute('href').split('#')[0].split('/').pop();
        if (linkPage === currentPage) {
            link.classList.add('active-link');
        }
    });
});
</script>

</body>
</html>