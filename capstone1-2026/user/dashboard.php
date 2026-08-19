<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require_once "../config/mailer.php";

$user_id   = $_SESSION['user_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? '';
$success   = '';
$error     = '';

$contact_success = '';
$contact_error   = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
    $c_first   = trim($_POST['first_name'] ?? '');
    $c_last    = trim($_POST['last_name'] ?? '');
    $c_email   = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $c_subject = trim($_POST['subject'] ?? '');
    $c_message = trim($_POST['message'] ?? '');

    if (empty($c_first) || empty($c_last) || empty($c_message)) {
        $contact_error = "Please fill in your name and message.";
    } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = "Please enter a valid email address.";
    } else {
        $c_full_name = htmlspecialchars($c_first . ' ' . $c_last, ENT_QUOTES, 'UTF-8');
        $c_safe_subj = $c_subject !== '' ? htmlspecialchars($c_subject, ENT_QUOTES, 'UTF-8') : 'New inquiry from website';
        $c_safe_msg  = nl2br(htmlspecialchars($c_message, ENT_QUOTES, 'UTF-8'));

        $c_bodyHtml = "<p><strong>From:</strong> {$c_full_name}</p>"
                    . "<p><strong>Email:</strong> {$c_email}</p>"
                    . "<p><strong>Message:</strong></p><p>{$c_safe_msg}</p>";

        $c_sent = sendMail('coravergelresort@gmail.com', 'CoraVergel Resort', "Website Inquiry: {$c_safe_subj}", $c_bodyHtml);

        if ($c_sent) {
            $contact_success = "Thanks, {$c_full_name}! Your message has been sent — we'll get back to you soon.";
        } else {
            $contact_error = "Something went wrong sending your message. Please try again or email us directly.";
        }
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
    <link rel="stylesheet" href="../assets/css/user.css">
    <link rel="stylesheet" href="../assets/css/contact-section.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
    /* ── Guest popup: age subtext + Done button ── */
    .guest-row { display: flex; align-items: center; justify-content: space-between; }
    .guest-age-sub {
        font-size: .74rem;
        color: #a09a89;
        margin-top: 2px;
    }
    .guest-popup-divider {
        height: 1px;
        background: #eee6d8;
        margin: 14px 0;
    }
    .guest-done-btn {
        width: 100%;
        height: 44px;
        border: none;
        border-radius: 9px;
        background: var(--navy, #1c2430);
        color: var(--gold, #e7b978);
        font-family: 'DM Sans', sans-serif;
        font-size: .85rem;
        font-weight: 600;
        letter-spacing: .04em;
        cursor: pointer;
        transition: background .15s ease;
        margin-top: 4px;
    }
    .guest-done-btn:hover { background: #141a24; }
    </style>

</head>
<body id="home">
<nav class="navbar">
 
    <!-- LEFT: hamburger (mobile) / nav links (desktop) -->
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">       
        <button class="nav-hamburger" id="navHamburger" onclick="openDrawer()" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <!-- Desktop links -->
        <div class="nav-links">
            <a href="about.php">about</a>
            <a href="rooms.php">rooms &amp; rates</a>
            <a href="gallery.php">gallery</a>
            <a href="deals.php">deals</a>
            <a href="dashboard.php#contact">contact</a>
        </div>
    </div>
 
    <a href="dashboard.php" class="navbar-brand">
        <div class="custom-logo">
            <!-- swap src to your actual logo -->
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
 
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

<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <h1>Welcome to CoraVergel Resort</h1>
        <p>Where family fun begins</p>
    </div>
</section>

 <section id="booking-section" class="floating-booking-wrap">
    <div class="fbb-card" id="bbarWrap">
 
      <div class="fbb-row">
        <div class="fbb-field" id="dateField">
          <span class="flbl">Date<span class="req">*</span></span>
          <div class="fval date-range-fval">
            <i class="fa-solid fa-calendar-days"></i>
            <input type="text" value="" id="dateRangeInput" placeholder="Select Date Range" aria-required="true" readonly autocomplete="on" aria-invalid="false"> 
          </div>
          <div class="ferr" id="dateErr">Please select your check-in and check-out dates.</div>
        </div>
        <div class="fbb-field" id="guestField" onclick="toggleGuests(event)">
          <div class="flbl">Guests</div>
          <div class="fval">
            <span id="guestDisplay">1 Room, 1 Adult, 0 Child</span>
            <svg viewBox="0 0 24 24" class="bbar-chevron-icon"><path d="M6 9l6 6 6-6"/></svg>
          </div>
 
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
              <div><div class="guest-lbl">Adults</div><div class="guest-age-sub">Ages 13+</div></div>
              <div class="g-counter">
                <button type="button" onclick="adj('adults',-1)">−</button>
                <span id="cAdults">1</span>
                <button type="button" onclick="adj('adults',1)">+</button>
              </div>
            </div>
            <div class="guest-row">
              <div><div class="guest-lbl">Children</div><div class="guest-age-sub">Ages 0–12</div></div>
              <div class="g-counter">
                <button type="button" onclick="adj('children',-1)">−</button>
                <span id="cChildren">0</span>
                <button type="button" onclick="adj('children',1)">+</button>
              </div>
            </div>
          </div>
        </div>
 
        <button type="button" class="fbb-book-btn" onclick="goToBooking()">
          Book Now
        </button>
      </div>
 
      <div class="fbb-benefits-divider"></div>
 
      <div class="fbb-benefits">
        <div class="fbb-benefit">
          <div class="fbb-benefit-icon"><i class="fa-solid fa-gift"></i></div>
          <div class="fbb-benefit-txt">Get more savings when you book direct! <a href="#">Learn More</a></div>
        </div>
      </div>
 
    </div>
  </section>
 
  <div class="page-spacer"></div>
 
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

<!-- ══════════ CONTACT ══════════ -->
<style>
/* ── Split Contact Section ── */
.cv-contact-wrap { max-width: 1080px; margin: 6rem auto; padding: 0 24px; }
.cv-contact-split {
  display: grid; grid-template-columns: .85fr 1.15fr;
  background: #fff; border-radius: 20px; overflow: hidden;
  box-shadow: 0 30px 70px rgba(26,18,5,.14);
}

/* left visual panel */
.cvc-visual {
  position: relative; background: var(--gold-deeper, #4a3819);
  padding: 52px 44px; color: #fff; overflow: hidden;
  display: flex; flex-direction: column; justify-content: space-between;
}
.cvc-visual::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(231,185,120,.12) 1px, transparent 1px);
  background-size: 22px 22px; pointer-events: none;
}
.cvc-visual::after {
  content: ''; position: absolute; bottom: -70px; right: -70px;
  width: 220px; height: 220px; border-radius: 50%;
  background: rgba(231,185,120,.08); pointer-events: none;
}
.cvc-eyebrow {
  position: relative; z-index: 1;
  font-family: 'DM Sans', sans-serif; font-size: 11px; font-weight: 700;
  letter-spacing: .22em; text-transform: uppercase; color: #e7b978; margin-bottom: 14px;
}
.cvc-title {
  position: relative; z-index: 1;
  font-family: 'Cormorant Garamond', serif; font-size: clamp(1.9rem, 3vw, 2.4rem);
  font-weight: 600; line-height: 1.15; margin-bottom: 16px;
}
.cvc-title em { font-style: italic; color: #e7b978; }
.cvc-sub {
  position: relative; z-index: 1;
  font-family: 'DM Sans', sans-serif; font-size: 13px; color: rgba(255,255,255,.6);
  line-height: 1.7; max-width: 30ch; margin-bottom: 32px;
}
.cvc-info-list { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 8px; margin-bottom: 28px; }
.cvc-info-row { display: flex; align-items: center; gap: 13px; padding: 10px 8px; border-radius: 10px; text-decoration: none; transition: background .2s; }
.cvc-info-row:hover { background: rgba(255,255,255,.06); }
.cvc-info-icon {
  width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px;
  background: rgba(231,185,120,.14); border: 1px solid rgba(231,185,120,.3);
  color: #e7b978; display: flex; align-items: center; justify-content: center; font-size: 13px;
}
.cvc-info-text { display: flex; flex-direction: column; gap: 1px; }
.cvc-info-lbl { font-family: 'DM Sans', sans-serif; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.4); }
.cvc-info-val { font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600; color: #fff; }
.cvc-socials { position: relative; z-index: 1; display: flex; gap: 9px; }
.cvc-socials a {
  width: 32px; height: 32px; border-radius: 50%;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
  color: rgba(255,255,255,.7); display: flex; align-items: center; justify-content: center;
  font-size: 12px; text-decoration: none; transition: all .2s;
}
.cvc-socials a:hover { background: #e7b978; border-color: #e7b978; color: #1c2430; transform: translateY(-2px); }

/* right form panel */
.cvc-form-panel { padding: 52px 48px; display: flex; flex-direction: column; justify-content: center; }
.cvc-form-eyebrow { font-family: 'DM Sans', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #a8895b; margin-bottom: 8px; }
.cvc-form-title { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 600; color: #1c2430; margin-bottom: 24px; }

.cvc-alert { display: flex; align-items: center; gap: 9px; padding: 11px 15px; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; margin-bottom: 18px; }
.cvc-alert.success { background: #eef6ee; color: #2f6b3a; border: 1px solid #cfe8cf; }
.cvc-alert.error   { background: #fbecec; color: #a13636; border: 1px solid #f3caca; }

.cvc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.cvc-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
.cvc-field label { font-family: 'DM Sans', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1c2430; opacity: .55; }
.cvc-field input, .cvc-field textarea {
  border: none; border-bottom: 1.5px solid #e4e0d8; background: transparent;
  padding: 9px 2px; font-family: 'DM Sans', sans-serif; font-size: 14px; color: #1c2430;
  outline: none; transition: border-color .2s;
}
.cvc-field input::placeholder, .cvc-field textarea::placeholder { color: #b7b2a4; }
.cvc-field input:focus, .cvc-field textarea:focus { border-color: #e7b978; }
.cvc-field textarea { resize: vertical; min-height: 90px; line-height: 1.5; }

.cvc-send {
  margin-top: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
  width: 100%; padding: 15px; background: #1c2430; color: #e7b978; border: 1.5px solid #e7b978;
  border-radius: 10px; font-family: 'DM Sans', sans-serif; font-weight: 700; font-size: 13.5px;
  letter-spacing: .03em; cursor: pointer; transition: all .25s;
}
.cvc-send:hover { background: #e7b978; color: #1c2430; transform: translateY(-2px); box-shadow: 0 12px 26px rgba(231,185,120,.35); }
.cvc-send:active { transform: translateY(0); }

@media (max-width: 860px) {
  .cv-contact-split { grid-template-columns: 1fr; }
  .cvc-visual { padding: 40px 30px; }
  .cvc-form-panel { padding: 40px 30px; }
}
@media (max-width: 480px) {
  .cv-contact-wrap { margin: 4rem auto; padding: 0 16px; }
  .cvc-row { grid-template-columns: 1fr; }
  .cvc-visual, .cvc-form-panel { padding: 32px 22px; }
}
</style>

<section id="contact">
  <div class="cv-contact-wrap">
    <div class="cv-contact-split">

      <!-- LEFT: visual / info panel -->
      <div class="cvc-visual">
        <div>
          <span class="cvc-eyebrow">Get In Touch</span>
          <h3 class="cvc-title">Let's plan your <em>escape</em></h3>
          <p class="cvc-sub">Questions about rooms, rates, or your stay? Send us a message and we'll reply by email.</p>

          <div class="cvc-info-list">
            <a href="mailto:coravergelresort@gmail.com" class="cvc-info-row">
              <span class="cvc-info-icon"><i class="fa-solid fa-envelope"></i></span>
              <span class="cvc-info-text">
                <span class="cvc-info-lbl">Email</span>
                <span class="cvc-info-val">coravergelresort@gmail.com</span>
              </span>
            </a>
            <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="cvc-info-row">
              <span class="cvc-info-icon"><i class="fa-solid fa-location-dot"></i></span>
              <span class="cvc-info-text">
                <span class="cvc-info-lbl">Address</span>
                <span class="cvc-info-val">21 Barosong, Tigbauan, Iloilo</span>
              </span>
            </a>
          </div>
        </div>

        <div class="cvc-socials">
          <a href="https://www.facebook.com/coravergelresort" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
          <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
          <a href="#" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
        </div>
      </div>

      <!-- RIGHT: form panel -->
      <div class="cvc-form-panel">
        <span class="cvc-form-eyebrow">Send a message</span>
        <h3 class="cvc-form-title">How can we help?</h3>

        <?php if ($contact_success): ?>
        <div class="cvc-alert success"><i class="fa-solid fa-circle-check"></i> <?= $contact_success ?></div>
        <?php endif; ?>
        <?php if ($contact_error): ?>
        <div class="cvc-alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($contact_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="dashboard.php#contact" id="contactForm">
          <input type="hidden" name="action" value="contact">
          <div class="cvc-row">
            <div class="cvc-field">
              <label>First name</label>
              <input type="text" name="first_name" required>
            </div>
            <div class="cvc-field">
              <label>Last name</label>
              <input type="text" name="last_name" required>
            </div>
          </div>
          <div class="cvc-field">
            <label>Email</label>
            <input type="email" name="email" placeholder="john@example.com" required>
          </div>
          <div class="cvc-field">
            <label>Subject</label>
            <input type="text" name="subject" placeholder="How can we help you?">
          </div>
          <div class="cvc-field">
            <label>Message</label>
            <textarea name="message" rows="3" placeholder="Tell us more about your inquiry..." required></textarea>
          </div>
          <button type="submit" class="cvc-send">
            Send Us a Message 
          </button>
        </form>
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
        document.getElementById('dateField').classList.add('fbb-field-active');   // NEW
    },

    onClose: function () {                                                        // NEW
        document.getElementById('dateField').classList.remove('fbb-field-active'); // NEW
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
 let state = { rooms:1, adults:1, children:0 };
 
  function toggleGuests(e){
    e.stopPropagation();
    document.getElementById('guestPopup').classList.toggle('open');
  }
  document.addEventListener('click', () => {
    document.getElementById('guestPopup').classList.remove('open');
  });
 
  function adj(type, delta){
    const min = type === 'rooms' ? 1 : (type === 'adults' ? 1 : 0);
    state[type] = Math.max(min, state[type] + delta);
    document.getElementById('c' + type.charAt(0).toUpperCase() + type.slice(1)).textContent = state[type];
    updateGuestDisplay();
  }
 
  function updateGuestDisplay(){
    const r = state.rooms, a = state.adults, c = state.children;
    document.getElementById('guestDisplay').textContent =
      `${r} Room${r>1?'s':''}, ${a} Adult${a>1?'s':''}, ${c} Child${c!==1?'ren':''}`;
  }
 
  function goToBooking(){
    const dateVal = document.getElementById('dateRangeInput').value;
    const err = document.getElementById('dateErr');
    if(!dateVal){
      err.style.display = 'block';
      return;
    }
    err.style.display = 'none';
    alert('Proceeding to booking with: ' + dateVal + ' — ' + document.getElementById('guestDisplay').textContent);
  }
 
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