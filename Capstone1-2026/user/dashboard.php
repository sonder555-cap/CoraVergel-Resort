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

<!-- NAVBAR -->
<nav class="navbar">
    <div class="nav-links">
        <a href="about.php">About</a>
        <a href="rooms.php">Rooms &amp; Rates</a>
        <a href="gallery.php">Gallery</a>
        <a href="deals.php">Deals</a>
        <a href="#contact" onclick="smoothScroll(event,'contact')">Contact</a>
    </div>

    <a href="#home" class="navbar-brand" onclick="smoothScroll(event,'home')">
        <div class="custom-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort" class="custom-logo-img">
        </div>
    </a>
        <div class="nav-login">
            <a href="../user/profile.php" class="profile-btn">
                <i class="fa-regular fa-user"></i> Profile
            </a>
        </div>
    </nav>

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

                    <!-- Date field -->
                    <div class="bbar-field" id="dateField" onclick="toggleCal(event)">
                        <div class="flbl">Date <span class="req">*</span></div>
                        <div class="fval">
                            <span id="dateDisplay">Select Date Range</span>
                            <svg viewBox="0 0 24 24" class="bbar-field-icon">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                        </div>
                        <div class="ferr" id="dateErr">Please select your check-in and check-out dates.</div>

                        <!-- Calendar popup -->
                        <div class="cal-popup" id="calPopup" onclick="event.stopPropagation()">
                            <div class="cal-header">
                                <button type="button" onclick="prevMonth()">&#8249;</button>
                                <div class="cal-header-selects">
                                    <select id="calMonth" onchange="goMonth()"></select>
                                    <select id="calYear"  onchange="goMonth()"></select>
                                </div>
                                <button type="button" onclick="nextMonth()">&#8250;</button>
                            </div>
                            <div class="cal-grid" id="calDow"></div>
                            <div class="cal-grid" id="calDays"></div>
                        </div>
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
                            <button type="button" class="guest-done" onclick="applyGuests()">Done</button>
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
<!-- ROOM SHOWCASE SECTION -->

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




<!-- ==================== BOOKING SECTION ==================== -->
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

    <!-- Lightbox -->
    <div class="cv-lb" id="cvLb">
        <button class="cv-lb-close" id="cvLbClose">&times;</button>
        <button class="cv-lb-prev" id="cvLbPrev">&#8249;</button>
        <img class="cv-lb-img" id="cvLbImg" src="" alt="">
        <button class="cv-lb-next" id="cvLbNext">&#8250;</button>
        <div class="cv-lb-caption" id="cvLbCap"></div>
    </div>

    <div class="cv-gallery">

        <!-- Header -->
        <div class="cv-gal-header">
            <div>
                <div class="cv-gal-eyebrow">Resort Gallery</div>
                <div class="cv-gal-title">A world of beauty<br><em>waiting for you</em></div>
            </div>
            <div id="gallery" class="cv-gal-subtitle">
                Nestled along the shores of Tigbauan, Iloilo —<br>a collection of moments worth remembering.
            </div>
        </div>

        <!-- Mosaic -->
        <div class="cv-gal-mosaic">

            <!-- Left — tall spanning image -->
            <div class="cv-gal-left cv-tile" data-src="../assets/images/1.jpg" data-caption="Aerial View">
                <img src="../assets/images/1.jpg" alt="Aerial View">
                <div class="cv-gal-tag">Aerial View</div>
            </div>

            <!-- Center — text panel -->
            <div class="cv-gal-center">
                <div  class="cv-gcp-eyebrow">Gallery</div>
                <div class="cv-gcp-title">Experience the beauty of CoraVergel Resort</div>
                <div class="cv-gcp-body">
                    From lush tropical gardens to crystal-clear swimming pools, CoraVergel Resort offers an unforgettable escape along the shores of Tigbauan, Iloilo.
                </div>
                <a href="#booking-section" onclick="smoothScroll(event,'booking-section')" class="cv-gcp-cta">Explore &amp; Book</a>
            </div>

            <!-- Right top -->
            <div class="cv-gal-rt cv-tile" data-src="../assets/images/2.jpg" data-caption="Cafe">
                <img src="../assets/images/2.jpg" alt="Cafe">
                <div class="cv-gal-tag">Cafe</div>
            </div>

            <!-- Right bottom -->
            <div class="cv-gal-rb cv-tile" data-src="../assets/images/11.jpg" data-caption="Swimming Pool">
                <img src="../assets/images/11.jpg" alt="Swimming Pool">
                <div class="cv-gal-tag">Swimming Pool</div>
            </div>

        </div>

    </div>
</section>

<!-- CONTACT BANNER -->
<section class="contact-section" id="contact-section">
    <div class="contact-inner">
        <div class="contact-form-side">
            <h3>Send Us a Message</h3>
            <div class="contact-card">
                <form method="POST" action="index.php#contact-section" id="contactForm">
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
            <a href="#contact" onclick="smoothScroll(event,'contact')">Contact Us</a>
        </div>
        <div class="footer-col">
            <h4>Experience</h4>
            <a href="#">Beachfront Activities</a>
            <a href="#">Wellness &amp; Spa</a>
            <a href="#">Resort Events</a>
            <a href="#">Dining</a>
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
<script>
/* ── Calendar ── */
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const gs     = { rooms: 1, adults: 1, children: 0 };
const now    = new Date();
let viewYear = now.getFullYear(), viewMonth = now.getMonth();
let rangeStart = null, rangeEnd = null;

function fmtDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function fmtDisplay(d) {
    const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return mo[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}

function buildSelects() {
    const ms = document.getElementById('calMonth');
    const ys = document.getElementById('calYear');
    ms.innerHTML = MONTHS.map((m, i) => `<option value="${i}">${m}</option>`).join('');
    ms.value = viewMonth;
    ys.innerHTML = '';
    for (let y = now.getFullYear(); y <= now.getFullYear() + 3; y++) {
        ys.innerHTML += `<option value="${y}">${y}</option>`;
    }
    ys.value = viewYear;
}

function renderCal() {
    buildSelects();
    document.getElementById('calDow').innerHTML = DAYS.map(d => `<div class="cal-dow">${d}</div>`).join('');
    const first  = new Date(viewYear, viewMonth, 1).getDay();
    const total  = new Date(viewYear, viewMonth + 1, 0).getDate();
    const today2 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    let html = '';
    for (let i = 0; i < first; i++) html += `<div class="cal-day empty"></div>`;
    for (let d = 1; d <= total; d++) {
        const thisDate = new Date(viewYear, viewMonth, d);
        const isPast   = thisDate < today2;
        const isToday  = thisDate.toDateString() === now.toDateString();
        let cls = 'cal-day';
        if (isPast)   cls += ' disabled';
        if (isToday)  cls += ' today';
        if (rangeStart && rangeEnd) {
            const t = thisDate.getTime(), s = rangeStart.getTime(), e = rangeEnd.getTime();
            if (t === s)        cls += ' range-start';
            else if (t === e)   cls += ' range-end';
            else if (t > s && t < e) cls += ' in-range';
        } else if (rangeStart && !rangeEnd) {
            if (thisDate.getTime() === rangeStart.getTime()) cls += ' range-start range-end';
        }
        html += `<div class="${cls}" onclick="pickDay(${d})">${d}</div>`;
    }
    document.getElementById('calDays').innerHTML = html;
}

function pickDay(d) {
    const clicked = new Date(viewYear, viewMonth, d);
    const today2  = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (clicked < today2) return;
    if (!rangeStart || rangeEnd) {
        rangeStart = clicked; rangeEnd = null;
        renderCal();
    } else {
        if (clicked <= rangeStart) {
            rangeStart = clicked; rangeEnd = null;
            renderCal();
        } else {
            rangeEnd = clicked;
            renderCal();
            document.getElementById('dateDisplay').textContent = fmtDisplay(rangeStart) + ' – ' + fmtDisplay(rangeEnd);
            document.getElementById('dateErr').style.display = 'none';
            setTimeout(() => document.getElementById('calPopup').classList.remove('open'), 300);
        }
    }
}

function prevMonth() {
    viewMonth--;
    if (viewMonth < 0) { viewMonth = 11; viewYear--; }
    const minY = now.getFullYear(), minM = now.getMonth();
    if (viewYear < minY || (viewYear === minY && viewMonth < minM)) { viewMonth = minM; viewYear = minY; }
    renderCal();
}
function nextMonth() {
    viewMonth++;
    if (viewMonth > 11) { viewMonth = 0; viewYear++; }
    renderCal();
}
function goMonth() {
    viewMonth = parseInt(document.getElementById('calMonth').value);
    viewYear  = parseInt(document.getElementById('calYear').value);
    renderCal();
}

function toggleCal(e) {
    e.stopPropagation();
    document.getElementById('guestPopup').classList.remove('open');
    const p = document.getElementById('calPopup');
    p.classList.toggle('open');
    if (p.classList.contains('open')) renderCal();
}

/* ── Guests ── */
function toggleGuests(e) {
    e.stopPropagation();
    document.getElementById('calPopup').classList.remove('open');
    document.getElementById('guestPopup').classList.toggle('open');
}
function adj(k, delta) {
    const mins = { rooms: 1, adults: 1, children: 0 };
    gs[k] = Math.max(mins[k], gs[k] + delta);
    document.getElementById('c' + k.charAt(0).toUpperCase() + k.slice(1)).textContent = gs[k];
}
function applyGuests() {
    document.getElementById('guestDisplay').textContent =
        gs.rooms   + ' Room'  + (gs.rooms   > 1 ? 's' : '') + ', ' +
        gs.adults  + ' Adult' + (gs.adults  > 1 ? 's' : '') + ', ' +
        gs.children + ' Child' + (gs.children > 1 ? 'ren' : '');
    document.getElementById('guestPopup').classList.remove('open');
}

/* ── Book Now — guest must log in first ── */
function goToBooking() {
    if (!rangeStart || !rangeEnd) {
        document.getElementById('dateErr').style.display = 'block';
        document.getElementById('calPopup').classList.add('open');
        renderCal();
        return;
    }
    document.getElementById('dateErr').style.display = 'none';
    document.getElementById('calPopup').classList.remove('open');
    document.getElementById('guestPopup').classList.remove('open');

    const totalGuests = gs.adults + gs.children;
    const params = new URLSearchParams({
        check_in:  fmtDate(rangeStart),
        check_out: fmtDate(rangeEnd),
        guests:    totalGuests
    });

    /* ── Key fix: carry selected room if one was passed via URL ── */
    const urlRoom = new URLSearchParams(window.location.search).get('room');
    if (urlRoom) params.set('room', urlRoom);

    window.location.href = 'rooms.php?' + params.toString();
}
/* ── Login modal controls ── */
function closeLPModal() {
    document.getElementById('loginPromptModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('loginPromptModal').addEventListener('click', function(e) {
    if (e.target === this) closeLPModal();
});

/* ── Gallery lightbox ── */
(function() {
    const tiles = Array.from(document.querySelectorAll('.cv-tile'));
    const lb    = document.getElementById('cvLb');
    const lbImg = document.getElementById('cvLbImg');
    const lbCap = document.getElementById('cvLbCap');
    let cur = 0;

    function open(i) {
        cur = i;
        lbImg.src = tiles[cur].dataset.src;
        lbCap.textContent = tiles[cur].dataset.caption || '';
        lb.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        lb.classList.remove('open');
        document.body.style.overflow = '';
    }
    function prev() {
        cur = (cur - 1 + tiles.length) % tiles.length;
        lbImg.src = tiles[cur].dataset.src;
        lbCap.textContent = tiles[cur].dataset.caption || '';
    }
    function next() {
        cur = (cur + 1) % tiles.length;
        lbImg.src = tiles[cur].dataset.src;
        lbCap.textContent = tiles[cur].dataset.caption || '';
    }

    tiles.forEach((t, i) => t.addEventListener('click', () => open(i)));
    document.getElementById('cvLbClose').addEventListener('click', close);
    document.getElementById('cvLbPrev').addEventListener('click', prev);
    document.getElementById('cvLbNext').addEventListener('click', next);
    lb.addEventListener('click', e => { if (e.target === lb) close(); });
    document.addEventListener('keydown', e => {
        if (!lb.classList.contains('open')) return;
        if (e.key === 'Escape')      close();
        if (e.key === 'ArrowLeft')   prev();
        if (e.key === 'ArrowRight')  next();
    });
})();

/* ── Utils ── */
function smoothScroll(e, id) { e.preventDefault(); smoothScrollTo(id); }
function smoothScrollTo(id) {
    const el = document.getElementById(id);
    if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
}
function changeLanguage(lang) { console.log('Language:', lang); }

document.addEventListener('click', function() {
    document.getElementById('calPopup').classList.remove('open');
    document.getElementById('guestPopup').classList.remove('open');
});

document.addEventListener('DOMContentLoaded', function() {
    const display = document.getElementById('dateDisplay');
    if (display && display.textContent.trim() === 'Select Date Range') {
        display.classList.add('placeholder');
    }
});

/* ── Room showcase tabs ── */
function switchRoom(tabIdx) {
    document.querySelectorAll('.rsc-top-tab').forEach((t, i) => t.classList.toggle('active', i === tabIdx));
    document.querySelectorAll('.rshowcase-panel').forEach((p, i) => p.classList.toggle('active', i === tabIdx));
}
</script>

</body>
</html>