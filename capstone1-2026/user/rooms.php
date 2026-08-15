<?php
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/availability.php';

/* ── URL params from dashboard ── */
$url_check_in  = trim($_GET['check_in']  ?? '');
$url_check_out = trim($_GET['check_out'] ?? '');
$url_guests    = intval($_GET['guests']  ?? 1);
$has_dates     = ($url_check_in !== '' && $url_check_out !== '');

/* ── Booking POST ── */
$booking_success = '';
$booking_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_room') {
    $room_type      = htmlspecialchars(strip_tags(trim($_POST['room_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $check_in       = trim($_POST['check_in'] ?? '');
    $check_out      = trim($_POST['check_out'] ?? '');
    $guests         = intval($_POST['guests'] ?? 0);
    $guest_name     = htmlspecialchars(strip_tags(trim($_POST['guest_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $guest_email    = htmlspecialchars(strip_tags(trim($_POST['guest_email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $id_type        = htmlspecialchars(strip_tags(trim($_POST['id_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $id_number      = htmlspecialchars(strip_tags(trim($_POST['id_number'] ?? '')), ENT_QUOTES, 'UTF-8');
    $contact_number = htmlspecialchars(strip_tags(trim($_POST['contact_number'] ?? '')), ENT_QUOTES, 'UTF-8');
    $id_photo       = '';

    if (empty($room_type) || empty($check_in) || empty($check_out) || $guests < 1) {
        $booking_error = "Missing booking details. Please try again.";
    } elseif (empty($guest_name) || empty($guest_email) || empty($id_type) || empty($id_number) || empty($contact_number)) {
        $booking_error = "Please fill in your personal details, email, valid ID, and contact number to confirm your booking.";
    } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
        $booking_error = "Please enter a valid email address.";
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
        $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $max_bytes    = 5 * 1024 * 1024; // 5MB

        $tmp_name  = $_FILES['id_photo']['tmp_name'];
        $orig_name = $_FILES['id_photo']['name'];
        $file_size = $_FILES['id_photo']['size'];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $mime      = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';

        if (!in_array($ext, $allowed_ext, true) || ($mime && !in_array($mime, $allowed_mime, true))) {
            $booking_error = "ID photo must be a JPG, PNG, WEBP, or PDF file.";
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
            $stmt = $conn->prepare("INSERT INTO bookings (room_type, check_in, check_out, guests, guest_name, guest_email, id_type, id_number, id_photo, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssissssss", $room_type, $check_in, $check_out, $guests, $guest_name, $guest_email, $id_type, $id_number, $id_photo, $contact_number);
            if ($stmt->execute()) {
                $booking_success = "Your booking for <strong>" . htmlspecialchars($room_type) . "</strong> has been submitted! We'll confirm it shortly — please keep your email and phone reachable.";
            } else {
                $booking_error = "Something went wrong. Please try again.";
            }
            $stmt->close();
        }
    }
}

/* ── Helpers ── */
function fmtDisplay($d) { return (new DateTime($d))->format('M j, Y'); }
function diffNights($ci, $co) { return max(1, (new DateTime($ci))->diff(new DateTime($co))->days); }

/* ── Room data: DATABASE is the source of truth for everything now,
   including description and image — both are set directly from the
   admin panel's Add/Edit Room form, so any room added there (with its
   photo and description) will show up here automatically, with no
   code changes needed.
   'cap', 'badge', and 'tags' aren't collected by the admin form yet,
   so those still fall back to sensible defaults. ── */
$default_img  = '../assets/images/standard_room.jpg';
$default_desc = 'A comfortable accommodation at CoraVergel Resort. Contact us for more details about this room.';
$default_meta = [
    'cap'   => 4,
    'badge' => 'Available',
    'tags'  => ['Free Entrance'],
];

$rooms = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image FROM rooms ORDER BY room_name");
if ($rq) {
    while ($row = $rq->fetch_assoc()) {
        $rooms[] = array_merge($default_meta, [
            'id'          => $row['room_name'],
            'room_id'     => $row['room_id'],
            'price'       => (float) $row['price'],
            'total_units' => (int) $row['total_units'],
            'desc'        => !empty($row['description']) ? $row['description'] : $default_desc,
            'img'         => !empty($row['image']) ? '../assets/images/rooms/' . $row['image'] : $default_img,
        ]);
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
    .modal-box {
        max-height: 90vh;
        display: flex;
        border-radius: 14px;
        max-width: 1000px;
        position: relative;
    }
    .modal-photo-wrap {
        position: relative;
        flex: 0 0 42%;
        border-radius: 14px 0 0 14px;
        overflow: hidden;
    }
    .modal-room-img-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .modal-close {
        position: absolute;
        top: -18px; right: -18px;
        width: 36px; height: 36px;
        border-radius: 50%;
        background: #fff;
        border: none;
        color: var(--navy);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        font-size: .9rem;
        box-shadow: 0 4px 16px rgba(0,0,0,.3);
        transition: background .15s ease, transform .15s ease;
        z-index: 20;
    }
    .modal-close:hover { background: var(--gold); transform: scale(1.06); }
    .modal-photo-caption {
        position: absolute;
        bottom: 0; left: 0; right: 0;
        padding: 20px;
        background: linear-gradient(transparent, rgba(0,0,0,.6));
        display: flex; align-items: baseline; justify-content: space-between;
    }
    .modal-photo-caption span:first-child {
        font-family: 'Cormorant Garamond', serif;
        font-weight: 600;
        font-size: 24px;
        color: #fff;
    }
    .modal-price {
        font-size: 13px;
        font-weight: 600;
        color: var(--gold);
    }
    .modal-right {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        border-radius: 0 14px 14px 0;
        overflow: hidden;
        background: #fff;
    }
    .modal-scroll {
        padding: 24px 26px 6px;
        overflow-y: auto;
        flex: 1;
    }
    .modal-summary-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 22px;
    }
    .ms-chip {
        background: var(--light);
        border-radius: 10px;
        padding: 10px 14px;
    }
    .ms-chip-lbl {
        font-size: .625rem;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #aaa;
        margin-bottom: 3px;
    }
    .ms-chip-val {
        font-size: .88rem;
        font-weight: 600;
        color: var(--navy);
    }
    .modal-section-lbl {
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #aaa;
        margin-bottom: 14px;
    }
    .cf-field { margin-bottom: 16px; }
    .cf-field label {
        display: block;
        font-size: .68rem;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #8a8578;
        margin-bottom: 6px;
    }
    .cf-field input,
    .cf-field select {
        width: 100%;
        height: 40px;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 0 12px;
        font-size: .88rem;
        font-family: 'DM Sans', sans-serif;
        color: var(--navy);
        box-sizing: border-box;
        outline: none;
        transition: border-color .15s ease;
    }
    .cf-field input:focus,
    .cf-field select:focus { border-color: var(--gold); }
    .modal-footer-bar {
        padding: 16px 26px;
        border-top: 1px solid #f0e8d5;
        display: flex; align-items: center; justify-content: space-between;
        background: #fff;
        flex-shrink: 0;
    }
    .mf-total-lbl {
        font-size: .625rem;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #aaa;
    }
    .mf-total-val {
        font-size: 1.19rem;
        font-weight: 700;
        color: var(--navy);
    }
    .modal-submit {
        padding: 0 28px;
        height: 44px;
        background: var(--navy);
        color: var(--gold);
        border: none;
        border-radius: 9px;
        font-size: .88rem;
        font-weight: 500;
        cursor: pointer;
        display: flex; align-items: center; gap: 7px;
        font-family: 'DM Sans', sans-serif;
        transition: all .2s ease;
    }
    .modal-submit:hover { background: var(--gold); color: var(--navy); }

    /* ── ID photo upload ── */
    .id-upload-wrap {
        border: 1.5px dashed var(--border);
        border-radius: 10px;
        padding: 14px;
        text-align: center;
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
        background: var(--light);
    }
    .id-upload-wrap:hover { border-color: var(--gold); }
    .id-upload-wrap.has-file { border-style: solid; border-color: #34A853; background: #f2f9f4; }
    .id-upload-wrap i { font-size: 1.1rem; color: #aaa; margin-bottom: 4px; display: block; }
    .id-upload-label { font-size: .8rem; color: #8a8578; }
    .id-upload-filename { font-size: .8rem; color: var(--navy); font-weight: 600; word-break: break-all; }
    .id-upload-hint { font-size: .68rem; color: #bbb; margin-top: 4px; }
    #idPhotoInput { display: none; }

    @media (max-width: 760px) {
        .modal-box { flex-direction: column; max-height: 92vh; }
        .modal-photo-wrap { flex: 0 0 180px; border-radius: 14px 14px 0 0; }
        .modal-right { border-radius: 0 0 14px 14px; }
        .modal-close { top: -14px; right: -14px; }
    }

    .room-img { cursor: pointer; }

    .room-zoom-hint {
        position: absolute;
        bottom: 14px;
        right: 14px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(0,0,0,.45);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .74rem;
        opacity: 0;
        transform: scale(.85);
        transition: opacity .2s ease, transform .2s ease, background .15s ease;
        pointer-events: none;
    }
    .room-card:hover .room-zoom-hint {
        opacity: 1;
        transform: scale(1);
    }
    .room-img:hover .room-zoom-hint {
        background: rgba(0,0,0,.6);
    }

    .room-lightbox {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1100;
        background: rgba(16,11,3,.9);
        align-items: center;
        justify-content: center;
        padding: 24px;
    }
    .room-lightbox.open {
        display: flex;
        animation: overlayFadeIn .2s ease;
    }
    .room-lightbox-img-wrap {
        position: relative;
        max-width: 90vw;
        max-height: 85vh;
    }
    .room-lightbox-img-wrap img {
        max-width: 90vw;
        max-height: 85vh;
        display: block;
        border-radius: 10px;
        object-fit: contain;
    }
    .room-lightbox-close {
        position: fixed;
        top: 22px;
        right: 26px;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255,255,255,.1);
        border: 1px solid rgba(255,255,255,.2);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: .95rem;
        transition: background .15s ease;
    }
    .room-lightbox-close:hover { background: rgba(255,255,255,.2); }
    .room-lightbox-caption {
        position: absolute;
        bottom: -38px;
        left: 0;
        right: 0;
        text-align: center;
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.1rem;
        color: rgba(255,255,255,.85);
    }

    .unavailable-alert {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        background: #fbeaf0;
        border: 1.5px solid #d4537e;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 28px;
    }
    .unavailable-alert-icon {
        flex-shrink: 0;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #f4c0d1;
        color: #993556;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .82rem;
        margin-top: 1px;
    }
    .unavailable-alert-title {
        font-size: .92rem;
        font-weight: 700;
        color: #72243e;
        margin-bottom: 3px;
    }
    .unavailable-alert-sub {
        font-size: .82rem;
        color: #993556;
        line-height: 1.5;
    }

    .search-bar-container_inner {
        position: relative;
        top: auto;
        padding: 0 0 28px;
        max-width: 550px;
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

    <a href="dashboard.php" class="navbar-brand">
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

        <button class="modal-close" onclick="closeModal()" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

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
            <input type="hidden" name="guests"    value="<?= $url_guests ?>">

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
                        <div class="ms-chip-val"><?= diffNights($url_check_in,$url_check_out) ?> night<?= diffNights($url_check_in,$url_check_out)!==1?'s':'' ?></div>
                    </div>
                    <div class="ms-chip">
                        <div class="ms-chip-lbl">Guests</div>
                        <div class="ms-chip-val"><?= $url_guests ?> guest<?= $url_guests!==1?'s':'' ?></div>
                    </div>
                </div>

                <div class="modal-section-lbl">Guest details</div>

                <div class="cf-field">
                    <label for="guestName">Full name</label>
                    <input type="text" id="guestName" name="guest_name" required placeholder="Juan Dela Cruz">
                </div>
                <div class="cf-field">
                    <label for="guestEmail">Email address</label>
                    <input type="email" id="guestEmail" name="guest_email" required placeholder="juan@gmail.com">
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
                        <div class="id-upload-hint">JPG, PNG, WEBP, or PDF · Max 5MB</div>
                    </label>
                    <input type="file" id="idPhotoInput" name="id_photo" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" onchange="handleIdPhotoChange()">
                </div>
                <div class="cf-field">
                    <label for="idNumber">ID number</label>
                    <input type="text" id="idNumber" name="id_number" required placeholder="ID number">
                </div>
                <div class="cf-field">
                    <label for="contactNumber">Contact number</label>
                    <input type="tel" id="contactNumber" name="contact_number" required placeholder="09XX XXX XXXX" pattern="^[0-9+\-\s]{7,15}$">
                </div>
            </div>

            <div class="modal-footer-bar">
                <div>
                    <div class="mf-total-lbl">Total estimate</div>
                    <div class="mf-total-val" id="modalTotal">₱0</div>
                </div>
                <button type="submit" class="modal-submit">
                    <i class="fa-solid fa-check"></i> Confirm booking
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

<!-- PAGE BODY -->
<div class="page-body page-body--no-hero">

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
                            aria-label="Guests, <?= $url_guests ?> guest<?= $url_guests !== 1 ? 's' : '' ?>"
                            aria-expanded="false"
                            aria-controls="guestsFlyoutContainer"
                            onclick="toggleDbGuests(event)"
                            id="dbGuestBox">
                        <span class="search-bar-container_label"><i class="fa-solid fa-user-group" aria-hidden="true"></i> Guests</span>
                        <span id="dbGuestVal"><?= $url_guests ?> Guest<?= $url_guests !== 1 ? 's' : '' ?></span>
                    </button>

                    <div class="guests-flyout-container" id="guestsFlyoutContainer" onclick="event.stopPropagation()">
                        <div class="g-row">
                            <div class="g-lbl">Guests</div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdj(-1)" aria-label="Decrease guests">−</button>
                                <span id="gCount"><?= $url_guests ?></span>
                                <button type="button" onclick="gAdj(1)" aria-label="Increase guests">+</button>
                            </div>
                        </div>
                        <button type="button" class="g-done" onclick="gDone()">Done</button>
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
                    <span class="search-bar-container_label"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Check-in</span>
                    <span id="dbCheckInVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                        <?= $has_dates ? fmtDisplay($url_check_in) : 'Select date' ?>
                    </span>
                </button>

                <!-- Check-out -->
                <button type="button"
                        class="search-bar-container_checkOut"
                        aria-label="Check-out <?= $has_dates ? fmtDisplay($url_check_out) : 'not selected' ?>"
                        aria-expanded="false"
                        aria-controls="calendarFlyoutContainer"
                        onclick="toggleDbCal(event)"
                        id="dbCheckOutBox">
                    <span class="search-bar-container_label"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Check-out</span>
                    <span id="dbCheckOutVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                        <?= $has_dates ? fmtDisplay($url_check_out) : 'Select date' ?>
                    </span>
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
                        <i class="fa-solid fa-arrow-right cal-summary-arrow" aria-hidden="true"></i>
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
            <span class="label-pill"><i class="fa-solid fa-moon"></i> Overnight Stay</span>
            <h2 class="section-heading">Accommodations</h2>
            <p class="section-sub">Unwind in comfort — every room includes free swimming &amp; resort entrance</p>
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
                    <span class="room-badge"><?= htmlspecialchars($r['badge']) ?></span>
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
                <p class="section-sub">Perfect for day trips, family gatherings, and poolside celebrations</p>
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
                <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-links">
        <div class="footer-col">
            <h4>About</h4>
            <a href="about.php">About CoraVergel</a>
            <a href="#">Awards &amp; Recognition</a>
            <a href="#">Sustainability</a>
            <a href="#">Careers</a>
            <a href="dashboard.php#contact">Contact Us</a>
        </div>
        <div class="footer-col">
            <h4>Stay</h4>
            <?php foreach ($rooms as $r): ?>
            <a href="rooms.php"><?= htmlspecialchars($r['id']) ?></a>
            <?php endforeach; ?>
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
            <a href="tel:3202512" class="topbar-link footer-contact-col">+320 2512</a>
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

let calY, calM, calStart = null, calEnd = null, gCount = URL_GUESTS;

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
    const p = new URLSearchParams({check_in:dStr(calStart), check_out:dStr(calEnd), guests:gCount});
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
function gAdj(d){ gCount = Math.max(1, gCount + d); document.getElementById('gCount').textContent = gCount; }
function gDone(){
    document.getElementById('dbGuestVal').textContent = gCount + ' Guest' + (gCount !== 1 ? 's' : '');
    document.getElementById('dbGuestBox').setAttribute('aria-label', 'Guests, ' + gCount + ' guest' + (gCount !== 1 ? 's' : ''));
    announce(gCount + ' guest' + (gCount !== 1 ? 's' : '') + ' selected');
    closeGuests();
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
    document.getElementById('bookModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(){
    document.getElementById('bookModal').classList.remove('open');
    document.body.style.overflow = '';
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
    document.getElementById('pageAlert')?.scrollIntoView({behavior:'smooth', block:'center'});
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
</script>

</body>
</html>