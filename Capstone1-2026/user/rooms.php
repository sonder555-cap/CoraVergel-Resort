<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

$is_logged_in = isset($_SESSION['user_id']);
if (!$is_logged_in) {
    header("Location: ../user/login.php");
    exit();
}

$full_name = $_SESSION['full_name'];
$user_id   = $_SESSION['user_id'];

/* ── URL params from dashboard ── */
$url_check_in  = trim($_GET['check_in']  ?? '');
$url_check_out = trim($_GET['check_out'] ?? '');
$url_guests    = intval($_GET['guests']  ?? 1);
$has_dates     = ($url_check_in !== '' && $url_check_out !== '');

/* ── Booking POST ── */
$booking_success = '';
$booking_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_room') {
    $room_type = htmlspecialchars(strip_tags(trim($_POST['room_type'])), ENT_QUOTES, 'UTF-8');
    $check_in  = trim($_POST['check_in']);
    $check_out = trim($_POST['check_out']);
    $guests    = intval($_POST['guests']);

    if (empty($room_type) || empty($check_in) || empty($check_out) || $guests < 1) {
        $booking_error = "Missing booking details. Please try again.";
    } elseif ($check_in < date('Y-m-d')) {
        $booking_error = "Check-in date cannot be in the past.";
    } elseif ($check_in >= $check_out) {
        $booking_error = "Check-out must be after check-in.";
    } else {
        $stmt = $conn->prepare("INSERT INTO bookings (user_id, room_type, check_in, check_out, guests) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $user_id, $room_type, $check_in, $check_out, $guests);
        if ($stmt->execute()) {
            $booking_success = "Your booking for <strong>" . htmlspecialchars($room_type) . "</strong> has been submitted! We'll confirm it shortly.";
        } else {
            $booking_error = "Something went wrong. Please try again.";
        }
        $stmt->close();
    }
}

/* ── Helpers ── */
function fmtDisplay($d) { return (new DateTime($d))->format('M j, Y'); }
function diffNights($ci, $co) { return max(1, (new DateTime($ci))->diff(new DateTime($co))->days); }
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
</head>
<body>

<!-- ══════════ TOPBAR ══════════ -->
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-lang">
                        <i class="fa-solid fa-globe"></i>
                        <select aria-label="Language">
                            <option value="en" selected>English</option>
                            <option value="fil">Filipino</option>
                        </select>
                    </div>
                </div>
                <div class="topbar-right">
                    <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link">
                        <i class="fa-solid fa-location-dot"></i>
                    </a>
                    <span class="topbar-divider">|</span>
                    <a href="mailto:coravergelresort@gmail.com" class="topbar-link">
                        <i class="fa-regular fa-envelope"></i>
                    </a>
                </div>
            </div>
<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">
 
    <!-- LEFT: hamburger (mobile) / nav links (desktop) -->
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">       
        <button class="nav-hamburger" id="navHamburger" onclick="openDrawer()" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <!-- Desktop links -->
        <div class="nav-links">
            <a href="about.php">ABOUT</a>
            <a href="rooms.php">ROOMS &amp; RATES</a>
            <a href="gallery.php">GALLERY</a>
            <a href="deals.php">DEALS</a>
            <a href="index.php#contact">CONTACT</a>
        </div>
    </div>
 
    <a href="../frontend/index.php" class="navbar-brand">
        <div class="custom-logo">
            <!-- swap src to your actual logo -->
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
 
<div class="nav-login">
    <div class="profile-dropdown-wrap" id="profileDropWrap">
            <button class="profile-btn" onclick="toggleProfileDrop(event)">
                <i class="fa-regular fa-user"></i>
            </button>
        <div class="profile-dropdown" id="profileDropdown">
            <a href="../user/profile.php?view=main" class="pd-item">
                <i class="fa-regular fa-user"></i> My Profile
            </a>
            <a href="../user/profile.php?view=main#all-bookings" class="pd-item">
                <i class="fa-solid fa-calendar-check"></i> My Bookings
            </a>
            <a href="../user/profile.php?view=notifications" class="pd-item">
                <i class="fa-regular fa-bell"></i> Notifications
            </a>
            <div class="pd-divider"></div>
            <a href="../user/logout.php" class="pd-item pd-item--logout">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</div>
 
</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

 
<!-- Slide Drawer -->
<div class="nav-drawer" id="navDrawer">

    <!-- Nav links -->
    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
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

<!-- HERO -->
<div class="rooms-hero">
    <img class="hero-bg-img" src="../assets/images/background.jpg" alt=""
         onerror="this.style.display='none'">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <div class="hero-eyebrow">
            <span class="hero-dot"></span>CoraVergel Resort<span class="hero-dot"></span>
        </div>
        <h1>Rooms &amp; Rates</h1>
        <p>Discover your perfect accommodation in paradise</p>
        <div class="hero-divider"></div>
    </div>
</div>


<!-- ALERTS -->
<?php if ($booking_success): ?>
<div class="page-alert page-alert--success" id="pageAlert">
    <i class="fa-solid fa-circle-check"></i>
    <div>
        <?= $booking_success ?>
        <a href="../user/profile.php" class="alert-link">View your bookings →</a>
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
        <button class="modal-close" onclick="closeModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="modal-head">
            <img class="modal-room-img-preview" id="modalImg" src="../assets/images/standard_room.jpg" alt="">
            <h3 id="modalRoomName">Room Name</h3>
            <p class="modal-price" id="modalRoomPrice">₱0 / night</p>
        </div>

        <?php if ($has_dates): ?>
        <form class="modal-form" method="POST"
              action="rooms.php?<?= http_build_query(['check_in'=>$url_check_in,'check_out'=>$url_check_out,'guests'=>$url_guests]) ?>"
              id="bookForm">
            <input type="hidden" name="action"    value="book_room">
            <input type="hidden" name="room_type" id="formRoomType" value="">
            <input type="hidden" name="check_in"  value="<?= htmlspecialchars($url_check_in) ?>">
            <input type="hidden" name="check_out" value="<?= htmlspecialchars($url_check_out) ?>">
            <input type="hidden" name="guests"    value="<?= $url_guests ?>">

            <div class="modal-summary">
                <div class="ms-row">
                    <span><i class="fa-regular fa-calendar"></i> Check-in</span>
                    <strong><?= fmtDisplay($url_check_in) ?></strong>
                </div>
                <div class="ms-row">
                    <span><i class="fa-regular fa-calendar-check"></i> Check-out</span>
                    <strong><?= fmtDisplay($url_check_out) ?></strong>
                </div>
                <div class="ms-row">
                    <span><i class="fa-solid fa-moon"></i> Duration</span>
                    <strong><?= diffNights($url_check_in,$url_check_out) ?> night<?= diffNights($url_check_in,$url_check_out)!==1?'s':'' ?></strong>
                </div>
                <div class="ms-row">
                    <span><i class="fa-solid fa-user-group"></i> Guests</span>
                    <strong><?= $url_guests ?> guest<?= $url_guests!==1?'s':'' ?></strong>
                </div>
            </div>

            <div class="modal-total">
                <span>Total Estimate</span>
                <strong id="modalTotal">₱0</strong>
            </div>

            <button type="submit" class="modal-submit">
                <i class="fa-solid fa-check"></i> Confirm Booking
            </button>
        </form>

        <?php else: ?>
        <!-- No dates yet — this state is now only shown if someone navigates directly without dates -->
        <div class="modal-form">
            <div class="modal-no-dates">
                <i class="fa-regular fa-calendar-days"></i>
                <p class="mnd-title">No dates selected yet</p>
                <p class="mnd-sub">Use the date bar above to pick your check-in and check-out dates, then come back to book.</p>
                <button onclick="closeModal(); toggleDbCal({stopPropagation:()=>{}})" class="modal-submit" style="margin-top:8px;">
                    <i class="fa-solid fa-calendar-days"></i> Pick Dates
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PAGE BODY -->
<div class="page-body">

<!-- STICKY DATE BAR -->
<div class="date-bar" id="dateBar" style="position:relative;top:auto;z-index:10;margin:0 0 0 0;border-top:1px solid var(--border);">
    <span class="db-label">Your Stay</span>

    <!-- Date picker field -->
    <div class="db-pill" id="dbDatePill" onclick="toggleDbCal(event)">
        <i class="fa-regular fa-calendar-days"></i>
        <?php if ($has_dates): ?>
            <span class="db-val" id="dbDateVal"><?= fmtDisplay($url_check_in) ?> &ndash; <?= fmtDisplay($url_check_out) ?></span>
        <?php else: ?>
            <span class="db-val" id="dbDateVal" style="display:none;"></span>
            <span class="db-placeholder" id="dbDatePlaceholder">Select dates</span>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-down db-chevron"></i>

        <!-- Calendar popup -->
        <div class="db-cal-popup" id="dbCalPopup" onclick="event.stopPropagation()">
            <div class="cal-header">
                <button class="cal-nav-btn" onclick="calPrev()"><i class="fa-solid fa-chevron-left"></i></button>
                <span id="calMonthLabel"></span>
                <button class="cal-nav-btn" onclick="calNext()"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <div class="cal-range-row">
                <div class="cal-range-item">
                    <div class="cal-range-lbl">Check-in</div>
                    <div class="cal-range-val <?= !$has_dates ? 'empty' : '' ?>" id="calFromVal">
                        <?= $has_dates ? fmtDisplay($url_check_in) : 'Select' ?>
                    </div>
                </div>
                <div style="color:#ddd;padding-top:14px;font-size:.8rem;">→</div>
                <div class="cal-range-item">
                    <div class="cal-range-lbl">Check-out</div>
                    <div class="cal-range-val <?= !$has_dates ? 'empty' : '' ?>" id="calToVal">
                        <?= $has_dates ? fmtDisplay($url_check_out) : 'Select' ?>
                    </div>
                </div>
            </div>
            <div class="cal-dow-row">
                <div class="cal-dow">Su</div><div class="cal-dow">Mo</div><div class="cal-dow">Tu</div>
                <div class="cal-dow">We</div><div class="cal-dow">Th</div><div class="cal-dow">Fr</div><div class="cal-dow">Sa</div>
            </div>
            <div class="cal-days-grid" id="calDaysGrid"></div>
            <div class="cal-footer">
                <span class="cal-summary" id="calSummary"></span>
                <button class="cal-clear" onclick="calClear()">Clear</button>
                <button class="cal-done" onclick="calDone()">Done</button>
            </div>
        </div>
    </div>

    <div class="db-divider"></div>

    <!-- Guests field -->
    <div class="db-pill" id="dbGuestPill" onclick="toggleDbGuests(event)">
        <i class="fa-solid fa-user-group"></i>
        <span class="db-val" id="dbGuestVal"><?= $url_guests ?> Guest<?= $url_guests !== 1 ? 's' : '' ?></span>
        <i class="fa-solid fa-chevron-down db-chevron"></i>

        <div class="db-guests-popup" id="dbGuestsPopup" onclick="event.stopPropagation()">
            <div class="g-row">
                <div class="g-lbl">Guests</div>
                <div class="g-counter">
                    <button type="button" onclick="gAdj(-1)">−</button>
                    <span id="gCount"><?= $url_guests ?></span>
                    <button type="button" onclick="gAdj(1)">+</button>
                </div>
            </div>
            <button class="g-done" onclick="gDone()">Done</button>
        </div>
    </div>

    <div class="db-divider"></div>

    <button class="db-update-btn" onclick="dbUpdate()">
        <i class="fa-solid fa-magnifying-glass"></i> Update
    </button>
</div>


    <!-- ══ OVERNIGHT ROOMS ══ -->
    <section class="content-section">
        <div class="section-label">
            <span class="label-pill"><i class="fa-solid fa-moon"></i> Overnight Stay</span>
            <h2 class="section-heading">Accommodations</h2>
            <p class="section-sub">Unwind in comfort — every room includes free swimming &amp; resort entrance</p>
        </div>

        <div class="rooms-grid">
            <?php
            $rooms = [
                ['id'=>'Duplex Room',      'cap'=>2,'price'=>3200,'img'=>'../assets/images/standard_room.jpg',   'badge'=>'Overnight',        'desc'=>'Comfortable air-conditioned duplex room with free entrance and swimming included. Perfect for couples or small groups.','tags'=>['AC','WiFi','Free Swimming','Free Entrance']],
                ['id'=>'Family Room',      'cap'=>7,'price'=>6000,'img'=>'../assets/images/family_room.jpg',     'badge'=>'Best for Families','desc'=>'Spacious family room for larger groups with free entrance and swimming for all overnight guests.','tags'=>['AC','WiFi','Free Swimming','Free Entrance','Large Space']],
                ['id'=>'Small Bahay Kubo', 'cap'=>4,'price'=>2100,'img'=>'../assets/images/small_bahay_kubo.jpg','badge'=>'Overnight',        'desc'=>'Cozy traditional Bahay Kubo-style accommodation with a relaxed and natural atmosphere.','tags'=>['Free Swimming','Free Entrance','Nature View']],
                ['id'=>'Large Bahay Kubo', 'cap'=>6,'price'=>3200,'img'=>'../assets/images/large_bahay_kubo.jpg','badge'=>'Popular',          'desc'=>'Spacious traditional Bahay Kubo perfect for groups, with beautiful resort grounds and amenities.','tags'=>['Free Swimming','Free Entrance','Nature View','Large Space']],
            ];
            foreach ($rooms as $r):
                $over_cap = $has_dates && $url_guests > $r['cap'];
                $nights   = $has_dates ? diffNights($url_check_in, $url_check_out) : null;
                $total    = $nights ? $r['price'] * $nights : null;
            ?>
            <div class="room-card <?= $over_cap ? 'room-card--dimmed' : '' ?>">
                <div class="room-img">
                    <img src="<?= $r['img'] ?>" alt="<?= htmlspecialchars($r['id']) ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
                    <span class="room-badge"><?= $r['badge'] ?></span>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> <?= $r['cap'] ?> pax</span>
                    <?php if ($over_cap): ?>
                    <div class="room-over-cap"><i class="fa-solid fa-users-slash"></i> Exceeds capacity</div>
                    <?php endif; ?>
                </div>
                <div class="room-body">
                    <h3 class="room-name"><?= htmlspecialchars($r['id']) ?></h3>
                    <p class="room-desc"><?= $r['desc'] ?></p>
                    <div class="room-tags">
                        <?php foreach ($r['tags'] as $t): ?>
                        <span class="room-tag"><?= $t ?></span>
                        <?php endforeach; ?>
                    </div>
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
                        <?php else: ?>
                        <button class="btn-book"
                            onclick="openModal('<?= addslashes($r['id']) ?>', <?= $r['price'] ?>, '<?= $r['img'] ?>')">
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
    function toggleProfileDrop(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('profileDropWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('profileDropdown').classList.remove('open');
    }   
});
/* ══════════════════════════════════════
   PHP → JS
══════════════════════════════════════ */
const HAS_DATES  = <?= $has_dates ? 'true' : 'false' ?>;
const URL_CI     = "<?= addslashes($url_check_in) ?>";
const URL_CO     = "<?= addslashes($url_check_out) ?>";
const URL_GUESTS = <?= $url_guests ?>;

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

function toggleDbCal(e){
    e.stopPropagation();
    document.getElementById('dbGuestsPopup').classList.remove('open');
    document.getElementById('dbGuestPill').classList.remove('open');
    const popup = document.getElementById('dbCalPopup');
    const pill  = document.getElementById('dbDatePill');
    const open  = popup.classList.toggle('open');
    pill.classList.toggle('open', open);
    if (open) renderCal();
}

function calPrev(){ calM--; if(calM<0){calM=11;calY--;} const n=new Date(); if(calY<n.getFullYear()||(calY===n.getFullYear()&&calM<n.getMonth())){calY=n.getFullYear();calM=n.getMonth();} renderCal(); }
function calNext(){ calM++; if(calM>11){calM=0;calY++;} renderCal(); }

function renderCal(){
    document.getElementById('calMonthLabel').textContent = MF[calM]+' '+calY;
    const first = new Date(calY,calM,1).getDay();
    const days  = new Date(calY,calM+1,0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);
    let h='';
    for(let i=0;i<first;i++) h+=`<button class="cal-day cal-empty" disabled></button>`;
    for(let d=1;d<=days;d++){
        const dt=new Date(calY,calM,d); let cls='cal-day';
        if(dt<today) cls+=' cal-disabled';
        if(dt.toDateString()===today.toDateString()) cls+=' cal-today';
        if(calStart&&calEnd){
            const t=dt.getTime(),s=calStart.getTime(),e=calEnd.getTime();
            if(t===s&&t===e) cls+=' cal-start cal-end';
            else if(t===s)   cls+=' cal-start';
            else if(t===e)   cls+=' cal-end';
            else if(t>s&&t<e) cls+=' cal-in-range';
        } else if(calStart&&dt.toDateString()===calStart.toDateString()) cls+=' cal-start cal-end';
        h+=`<button class="${cls}" onclick="calPick(${calY},${calM},${d})">${d}</button>`;
    }
    document.getElementById('calDaysGrid').innerHTML=h;
    const fEl=document.getElementById('calFromVal'), tEl=document.getElementById('calToVal');
    fEl.textContent=calStart?dDisp(calStart):'Select'; fEl.className='cal-range-val'+(calStart?'':' empty');
    tEl.textContent=calEnd  ?dDisp(calEnd)  :'Select'; tEl.className='cal-range-val'+(calEnd  ?'':' empty');
    const s=document.getElementById('calSummary');
    if(calStart&&calEnd){ const n=Math.round((calEnd-calStart)/86400000); s.textContent=n+' night'+(n!==1?'s':'')+' selected'; }
    else if(calStart){ s.textContent='Now pick check-out'; }
    else { s.textContent=''; }
}

function calPick(y,m,d){
    const dt=new Date(y,m,d); const today=new Date(); today.setHours(0,0,0,0);
    if(dt<today) return;
    if(!calStart||(calStart&&calEnd)){ calStart=dt; calEnd=null; }
    else { if(dt<=calStart){ calStart=dt; calEnd=null; } else { calEnd=dt; } }
    renderCal();
}

function calDone(){
    if(!calStart||!calEnd) return;
    const label=dDisp(calStart)+' – '+dDisp(calEnd);
    document.getElementById('dbDateVal').textContent=label;
    document.getElementById('dbDateVal').style.display='';
    const ph=document.getElementById('dbDatePlaceholder');
    if(ph) ph.style.display='none';
    document.getElementById('dbCalPopup').classList.remove('open');
    document.getElementById('dbDatePill').classList.remove('open');
}

function calClear(){
    calStart=null; calEnd=null;
    document.getElementById('dbDateVal').textContent='';
    document.getElementById('dbDateVal').style.display='none';
    const ph=document.getElementById('dbDatePlaceholder');
    if(ph) ph.style.display='';
    renderCal();
}

/* ── Guests ── */
function toggleDbGuests(e){
    e.stopPropagation();
    document.getElementById('dbCalPopup').classList.remove('open');
    document.getElementById('dbDatePill').classList.remove('open');
    const p=document.getElementById('dbGuestsPopup'), pill=document.getElementById('dbGuestPill');
    const open=p.classList.toggle('open'); pill.classList.toggle('open',open);
}
function gAdj(d){ gCount=Math.max(1,gCount+d); document.getElementById('gCount').textContent=gCount; }
function gDone(){
    document.getElementById('dbGuestVal').textContent=gCount+' Guest'+(gCount!==1?'s':'');
    document.getElementById('dbGuestsPopup').classList.remove('open');
    document.getElementById('dbGuestPill').classList.remove('open');
}

/* ── Update button ── */
function dbUpdate(){
    if(!calStart||!calEnd){
        toggleDbCal({stopPropagation:()=>{}}); return;
    }
    const p=new URLSearchParams({check_in:dStr(calStart),check_out:dStr(calEnd),guests:gCount});
    window.location.href='rooms.php?'+p.toString();
}

/* Close on outside click */
document.addEventListener('click',function(e){
    const dp=document.getElementById('dbDatePill');
    const gp=document.getElementById('dbGuestPill');
    if(dp&&!dp.contains(e.target)){ document.getElementById('dbCalPopup').classList.remove('open'); dp.classList.remove('open'); }
    if(gp&&!gp.contains(e.target)){ document.getElementById('dbGuestsPopup').classList.remove('open'); gp.classList.remove('open'); }
});

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
    document.getElementById('modalRoomPrice').textContent = '₱' + price.toLocaleString() + ' / night';
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
    if (urlRoom) {
        const roomMap = {
            'Duplex Room':      { price: 3200, img: '../assets/images/standard_room.jpg' },
            'Family Room':      { price: 6000, img: '../assets/images/family_room.jpg' },
            'Small Bahay Kubo': { price: 2100, img: '../assets/images/small_bahay_kubo.jpg' },
            'Large Bahay Kubo': { price: 3200, img: '../assets/images/large_bahay_kubo.jpg' },
        };
        const r = roomMap[urlRoom];
        if (r) openModal(urlRoom, r.price, r.img);
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