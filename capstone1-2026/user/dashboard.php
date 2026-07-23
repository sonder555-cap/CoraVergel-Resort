<?php
session_start(); 
require "../config/conn.php";
require "../config/security.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$success   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book') {
    $room_type = htmlspecialchars(strip_tags(trim($_POST['room_type'])), ENT_QUOTES, 'UTF-8');
    $check_in  = trim($_POST['check_in']);
    $check_out = trim($_POST['check_out']);
    $guests    = intval($_POST['guests']);

    if (empty($room_type) || empty($check_in) || empty($check_out) || $guests < 1) {
        $error = "Please fill in all fields correctly.";
    } elseif ($check_in < date('Y-m-d')) {
        $error = "Check-in date cannot be in the past.";
    } elseif ($check_in >= $check_out) {
        $error = "Check-out date must be after check-in date.";
    } else {
        $stmt = $conn->prepare("INSERT INTO bookings (user_id, room_type, check_in, check_out, guests) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $user_id, $room_type, $check_in, $check_out, $guests);
        if ($stmt->execute()) {
            $success = "Your booking has been submitted successfully! We'll confirm shortly.";
        } else {
            $error = "Something went wrong. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoraVergel Resort</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/index.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

</head>
<body id="home">

<!-- TOPBAR -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-lang">
            <i class="fa-solid fa-globe"></i>
            <select onchange="changeLanguage(this.value)" aria-label="Select Language">
                <option value="en" selected>English</option>
                <option value="fil">Filipino</option>
            </select>
        </div>
    </div>
    <div class="topbar-right">
        <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link"><i class="fa-solid fa-location-dot"></i></a>
        <a href="mailto:coravergelresort@gmail.com" class="topbar-link"><i class="fa-regular fa-envelope"></i></a>    
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
<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <h1>Welcome to CoraVergel Resort</h1>
        <p>Your Paradise Destination for Unforgettable Experiences</p>
        <div class="cta-buttons">
            <a href="#booking-section" onclick="smoothScroll(event, 'booking-section')" class="btn primary">Book Now</a>
        </div>
    </div>
</section>

<section id="booking-section" class="booking-section-wrap">
    <div class="booking-bar-section">
        <div id="step1Wrap">
            <div class="bbar-wrap" id="bbarWrap">

                <div class="bbar-fields">

                    <!-- Date range field (Flatpickr) -->
                    <div class="bbar-field" id="dateField" >
                        <div class="flbl">Date <span class="req">*</span></div>
                        <div class="fval date-range-fval">
                            <input type="text"
                                   id="dateRangeInput"
                                   placeholder="Select Date Range"
                                   readonly
                                   autocomplete="on">
                            <div class="date-cal-icon-btn" >
                           <i class="fa-solid fa-calendar-days"></i>
                            </div>
                        </div>
                        <div class="ferr" id="dateErr">Please select your check-in and check-out dates.</div>
                    </div>

                    <!-- Guests field -->
                    <div class="bbar-field" id="guestField" onclick="toggleGuests(event)">
                        <div class="flbl">Guests</div>
                        <div class="fval">
                            <span id="guestDisplay">1 Room, 1 Adult, 0 Child</span>
                            <svg viewBox="0 0 24 24" class="bbar-chevron-icon">
                                <path d="M6 9l6 6 6-6"/>
                            </svg>
                        </div>

                        <!-- Guests popup -->
<div class="guest-popup" id="guestPopup" onclick="event.stopPropagation()">
    <div class="guest-row">
        <div><div class="guest-lbl">Rooms</div></div>
        <div class="g-counter">
            <button type="button" onclick="adj('rooms',-1)">−</button>
            <span id="cRooms">1</span>
            <button type="button" onclick="adj('rooms',1)">+</button>
        </div>
    </div>
    <div class="guest-row">
        <div><div class="guest-lbl">Adults</div></div>
        <div class="g-counter">
            <button type="button" onclick="adj('adults',-1)">−</button>
            <span id="cAdults">1</span>
            <button type="button" onclick="adj('adults',1)">+</button>
        </div>
    </div>
    <div class="guest-row">
        <div><div class="guest-lbl">Children</div></div>
        <div class="g-counter">
            <button type="button" onclick="adj('children',-1)">−</button>
            <span id="cChildren">0</span>
            <button type="button" onclick="adj('children',1)">+</button>
        </div>
    </div>
</div>
                    </div>

                    <button type="button" class="bbar-next-btn" onclick="goToBooking()">Book Now</button>
                </div>

                <div class="bbar-divider"></div>
                <div class="bbar-benefits">
                    <div class="bbar-benefit">
                        <div class="bbar-benefit-icon">
                            <svg viewBox="0 0 24 24"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/></svg>
                        </div>
                        <div class="bbar-benefit-txt">Get more savings when you book direct! <a href="special_offers.php" class="bbar-benefit-link">Learn More</a></div>
                    </div>
                    <div class="bbar-benefit">
                        <div class="bbar-benefit-icon">
                            <svg viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11a2 2 0 012 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/></svg>
                        </div>
                        <div class="bbar-benefit-txt">Enjoy complimentary round-trip airport transfers</div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<!-- WHY SECTION -->
<section class="why-section" id="about">
    <h2>Why Choose CoraVergel?</h2>
    <p class="section-sub">Experience world-class hospitality in a breathtaking natural setting</p>
    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon">🌊</div>
            <h3>Beachfront Location</h3>
            <p>Wake up to stunning ocean views and pristine white sand just steps from your room.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🏡</div>
            <h3>Luxury Accommodations</h3>
            <p>Thoughtfully designed rooms and villas that blend comfort with natural elegance.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🍽️</div>
            <h3>Fine Dining</h3>
            <p>Savor fresh local cuisine and international dishes crafted by our expert chefs.</p>
        </div>
    </div>
</section>

<!-- ROOM SHOWCASE SECTION -->
<section class="rshowcase-section">
    <div class="rsc-top-tabs">
        <button class="rsc-top-tab active" onclick="switchRoom(0)">ROOMS</button>
        <button class="rsc-top-tab" onclick="switchRoom(1)">COTTAGES</button>
    </div>

    <!-- Rooms panel -->
    <div class="rshowcase-panel active" id="rsp-0">
        <div class="rshowcase-cards" id="rsc-0">
            <div class="rshowcase-card active">
                <img class="rsc-bg-img" src="../assets/images/11.jpg" alt="Duplex Room">
                <div class="rsc-overlay"></div>
                <div class="rsc-headline">Comfort<br>Beyond<br>Compare</div>
                <div class="rsc-info">
                    <div class="rsc-info-name">Duplex Room</div>
                    <div class="rsc-info-desc">Air-conditioned duplex with free swimming &amp; entrance. Perfect for couples or small groups.</div>
                    <div class="rsc-info-row"></div>
                    <div class="rsc-tags"><span>AC</span><span>WiFi</span><span>Free Swimming</span></div>
                    <a href="rooms.php" class="rsc-cta">EXPLORE ROOMS</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Cottages panel -->
    <div class="rshowcase-panel" id="rsp-1">
        <div class="rshowcase-cards" id="rsc-1">
            <div class="rshowcase-card active">
                <img class="rsc-bg-img" src="../assets/images/COTTAGES.jpg" alt="Large Gazebo">
                <div class="rsc-overlay"></div>
                <div class="rsc-headline">Gather<br>Under<br>Open Skies</div>
                <div class="rsc-info">
                    <div class="rsc-info-name">Large Gazebo</div>
                    <div class="rsc-info-desc">Poolside day-use cottage for big gatherings. Cool shade, great vibes, near the swimming pool.</div>
                    <div class="rsc-tags"><span>Day Use</span><span>Near Pool</span></div>
                    <a href="rooms.php" class="rsc-cta">EXPLORE COTTAGES</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- GALLERY -->
<section>
    <div class="cv-lb" id="cvLb">
        <button class="cv-lb-close" id="cvLbClose">&times;</button>
        <button class="cv-lb-prev" id="cvLbPrev">&#8249;</button>
        <img class="cv-lb-img" id="cvLbImg" src="" alt="">
        <button class="cv-lb-next" id="cvLbNext">&#8250;</button>
        <div class="cv-lb-caption" id="cvLbCap"></div>
    </div>

    <div class="cv-gallery">
        <div class="cv-gal-header">
            <div>
                <div class="cv-gal-eyebrow">Resort Gallery</div>
                <div class="cv-gal-title">A world of beauty<br><em>waiting for you</em></div>
            </div>
            <div id="gallery" class="cv-gal-subtitle">
                Nestled along the shores of Tigbauan, Iloilo —<br>a collection of moments worth remembering.
            </div>
        </div>

        <div class="cv-gal-mosaic">
            <div class="cv-gal-left cv-tile" data-src="../assets/images/1.jpg" data-caption="Aerial View">
                <img src="../assets/images/1.jpg" alt="Aerial View">
                <div class="cv-gal-tag">Aerial View</div>
            </div>
            <div class="cv-gal-center">
                <div class="cv-gcp-eyebrow">Gallery</div>
                <div class="cv-gcp-title">Experience the beauty of CoraVergel Resort</div>
                <div class="cv-gcp-body">
                    From lush tropical gardens to crystal-clear swimming pools, CoraVergel Resort offers an unforgettable escape along the shores of Tigbauan, Iloilo.
                </div>
                <a href="gallery.php" class="cv-gcp-cta">Explore Gallery</a>
            </div>
            <div class="cv-gal-rt cv-tile" data-src="../assets/images/2.jpg" data-caption="Cafe">
                <img src="../assets/images/2.jpg" alt="Cafe">
                <div class="cv-gal-tag">Cafe</div>
            </div>
            <div class="cv-gal-rb cv-tile" data-src="../assets/images/11.jpg" data-caption="Swimming Pool">
                <img src="../assets/images/11.jpg" alt="Swimming Pool">
                <div class="cv-gal-tag">Swimming Pool</div>
            </div>
        </div>
    </div>
</section>
<div class="cta-banner" style="cursor:pointer;" onclick="smoothScrollTo('booking-section')">
    <h2>Don't Miss Out on These Deals</h2>
    <p>Slots are limited — book your CoraVergel experience today before they're gone.</p>
    <a href="deals.php" onclick="smoothScroll(event,'booking-section')" class="cta-btn">
        <i class="fa-solid fa-calendar-check"></i>
        DEALS &amp; OFFERS
    </a>
</div>

<!-- CONTACT BANNER -->
<section class="contact-section" id="contact">
    <div class="contact-inner">
        <div class="contact-form-side">
            <h3>Send Us a Message</h3>
            <div class="contact-card">
                <form method="POST" action="dashboard.php#contact" id="contactForm">
                    <input type="hidden" name="action" value="contact">
                    <div class="contact-name-row">
                        <div class="contact-field">
                            <label>First Name</label>
                            <input type="text" name="first_name" placeholder="John" required>
                        </div>
                        <div class="contact-field">
                            <label>Last Name</label>
                            <input type="text" name="last_name" placeholder="Doe" required>
                        </div>
                    </div>
                    <div class="contact-field">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="john@example.com" required>
                    </div>
                    <div class="contact-field">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="+63 912 345 6789">
                    </div>
                    <div class="contact-field">
                        <label>Subject</label>
                        <input type="text" name="subject" placeholder="How can we help you?">
                    </div>
                    <div class="contact-field">
                        <label>Message</label>
                        <textarea name="message" placeholder="Tell us more about your inquiry..." required></textarea>
                    </div>
                    <button type="submit" class="btn-send">Send Message</button>
                </form>
            </div>
        </div>
        <div class="contact-info-side">
            <h3>Get in Touch</h3>
            <div class="contact-info-card">
                <div class="info-title"><i class="fa-solid fa-location-dot"></i> Address</div>
                <p>5021 Barosong, Tigbauan,</p>
                <p>Iloilo City, Philippines</p>
            </div>
            <div class="contact-info-card">
                <div class="info-title"><i class="fa-solid fa-phone"></i> Phone</div>
                <p>Reservations: +63 912 345 6789</p>
            </div>
            <div class="contact-info-card">
                <div class="info-title"><i class="fa-solid fa-envelope"></i> Email</div>
                <p>bookings@coravergel.com</p>
            </div>
        </div>
    </div>
</section>

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
            <a href="tel:320 2512" class="topbar-link footer-contact-col">+320 2512</a>
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

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
/* ── Flatpickr date range ── */
let checkInVal  = '';
let checkOutVal = '';
let fpInstance  = null;

document.addEventListener('DOMContentLoaded', function () {

    const urlParams  = new URLSearchParams(window.location.search);
    const preselRoom = urlParams.get('room');

    if (preselRoom) {
        const hint = document.createElement('div');
        hint.id = 'roomHint';
        hint.style.cssText = 'text-align:center;margin-bottom:10px;font-size:0.85rem;color:#8b6914;letter-spacing:0.04em;';
        hint.innerHTML = '<i class="fa-solid fa-circle-info" style="margin-right:5px;"></i>Pick your dates to book: <strong>' + preselRoom + '</strong>';
        const bbarWrap = document.getElementById('bbarWrap');
        if (bbarWrap) bbarWrap.parentNode.insertBefore(hint, bbarWrap);
    }

fpInstance = flatpickr('#dateRangeInput', {
    mode         : 'range',
    minDate      : 'today',
    dateFormat   : 'Y-m-d',
    disableMobile: true,
    formatDate   : function() { return ''; },

onChange: function (selectedDates, dateStr, instance) {
    if (selectedDates.length === 2) {
        checkInVal  = flatpickr.formatDate(selectedDates[0], 'Y-m-d');
        checkOutVal = flatpickr.formatDate(selectedDates[1], 'Y-m-d');
        document.getElementById('dateRangeInput').value = checkInVal + ' to ' + checkOutVal;
        document.getElementById('dateRangeInput').classList.add('has-date');
        document.getElementById('dateErr').style.display = 'none';
    } else {
        checkInVal  = '';
        checkOutVal = '';
        instance.input.value = '';
        document.getElementById('dateRangeInput').classList.remove('has-date');
    }
},  

    onReady: function (selectedDates, dateStr, instance) {
        if (preselRoom) {
            setTimeout(() => instance.open(), 400);
        }
    },

    onOpen: function () {
        document.getElementById('guestPopup').classList.remove('open');
    }
});

    /* Clicking anywhere in the date field opens the picker */
    document.getElementById('dateField').addEventListener('click', function () {
        fpInstance && fpInstance.open();
    });
});
/* ── Profile dropdown ── */
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

/* ── Guests ── */
const gs = { rooms: 1, adults: 1, children: 0 };

function toggleGuests(e) {
    e.stopPropagation();
    fpInstance && fpInstance.close();
    document.getElementById('guestPopup').classList.toggle('open');
}
function adj(k, delta) {
    const mins = { rooms: 1, adults: 1, children: 0 };
    gs[k] = Math.max(mins[k], gs[k] + delta);
    document.getElementById('c' + k.charAt(0).toUpperCase() + k.slice(1)).textContent = gs[k];
    // Auto-update display immediately
    document.getElementById('guestDisplay').textContent =
        gs.rooms    + ' Room'  + (gs.rooms    > 1 ? 's'   : '') + ', ' +
        gs.adults   + ' Adult' + (gs.adults   > 1 ? 's'   : '') + ', ' +
        gs.children + ' Child' + (gs.children > 1 ? 'ren' : '');
}
function applyGuests() {
    document.getElementById('guestDisplay').textContent =
        gs.rooms    + ' Room'  + (gs.rooms    > 1 ? 's'   : '') + ', ' +
        gs.adults   + ' Adult' + (gs.adults   > 1 ? 's'   : '') + ', ' +
        gs.children + ' Child' + (gs.children > 1 ? 'ren' : '');
    document.getElementById('guestPopup').classList.remove('open');
}

/* ── Book Now ── */
function goToBooking() {
    fpInstance && fpInstance.close();
    document.getElementById('guestPopup').classList.remove('open');

    if (!checkInVal || !checkOutVal) {
        document.getElementById('dateErr').style.display = 'block';
        fpInstance && fpInstance.open();
        return;
    }

    document.getElementById('dateErr').style.display = 'none';

    const totalGuests = gs.adults + gs.children;
    const params = new URLSearchParams({
        check_in : checkInVal,
        check_out: checkOutVal,
        guests   : totalGuests
    });

    const urlRoom = new URLSearchParams(window.location.search).get('room');
    if (urlRoom) params.set('room', urlRoom);

    window.location.href = 'rooms.php?' + params.toString();
}

/* ── Gallery lightbox ── */
(function () {
    const tiles = Array.from(document.querySelectorAll('.cv-tile'));
    const lb    = document.getElementById('cvLb');
    const lbImg = document.getElementById('cvLbImg');
    const lbCap = document.getElementById('cvLbCap');
    let cur = 0;

    function open(i)  { cur = i; lbImg.src = tiles[cur].dataset.src; lbCap.textContent = tiles[cur].dataset.caption || ''; lb.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function close()  { lb.classList.remove('open'); document.body.style.overflow = ''; }
    function prev()   { cur = (cur - 1 + tiles.length) % tiles.length; lbImg.src = tiles[cur].dataset.src; lbCap.textContent = tiles[cur].dataset.caption || ''; }
    function next()   { cur = (cur + 1) % tiles.length; lbImg.src = tiles[cur].dataset.src; lbCap.textContent = tiles[cur].dataset.caption || ''; }

    tiles.forEach((t, i) => t.addEventListener('click', () => open(i)));
    document.getElementById('cvLbClose').addEventListener('click', close);
    document.getElementById('cvLbPrev').addEventListener('click',  prev);
    document.getElementById('cvLbNext').addEventListener('click',  next);
    lb.addEventListener('click', e => { if (e.target === lb) close(); });
    document.addEventListener('keydown', e => {
        if (!lb.classList.contains('open')) return;
        if (e.key === 'Escape')     close();
        if (e.key === 'ArrowLeft')  prev();
        if (e.key === 'ArrowRight') next();
    });
})();

/* ── Utils ── */
function smoothScroll(e, id) { e.preventDefault(); smoothScrollTo(id); }
function smoothScrollTo(id) {
    const el = document.getElementById(id);
    if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
}
function changeLanguage(lang) { console.log('Language:', lang); }

document.addEventListener('click', function (e) {
    const gf = document.getElementById('guestField');
    if (gf && !gf.contains(e.target)) {
        document.getElementById('guestPopup').classList.remove('open');
    }
});

function switchRoom(tabIdx) {
    document.querySelectorAll('.rsc-top-tab').forEach((t, i)     => t.classList.toggle('active', i === tabIdx));
    document.querySelectorAll('.rshowcase-panel').forEach((p, i) => p.classList.toggle('active', i === tabIdx));
}
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

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

</body>
</html>