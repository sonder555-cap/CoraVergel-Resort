<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require_once "../config/mailer.php";
require_once "../config/csrf.php";

$user_id   = $_SESSION['user_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? '';
$success   = '';
$error     = '';

$contact_success = '';
$contact_error   = '';

/* Booking submissions are handled by user/rooms.php.
   The homepage booking bar redirects there with the selected dates,
   guest counts, and room count, so there is only one booking writer. */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
    csrfVerify();
    $c_email   = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $c_message = trim($_POST['message'] ?? '');

    if (empty($c_message)) {
        $contact_error = "Please enter a message.";
    } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = "Please enter a valid email address.";
    } else {
        $c_safe_msg  = nl2br(htmlspecialchars($c_message, ENT_QUOTES, 'UTF-8'));

        $c_bodyHtml = "<p><strong>From:</strong> {$c_email}</p>"
                    . "<p><strong>Message:</strong></p><p>{$c_safe_msg}</p>";

        $c_sent = sendMail('coravergelresort@gmail.com', 'CoraVergel Resort', "Website Inquiry from {$c_email}", $c_bodyHtml);

        if ($c_sent) {
            $contact_success = "Thanks! Your message has been sent — we'll get back to you soon.";
        } else {
            $contact_error = "Something went wrong sending your message. Please try again or email us directly.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" xmlns:og="http://opengraphprotocol.org/schema/">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoraVergel Resort</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="CoraVergel Resort">
    <meta property="og:title" content="CoraVergel Resort — Tigbauan, Iloilo">
    <meta property="og:description" content="Air-conditioned rooms, cottages, and swimming pools in Tigbauan, Iloilo. Book direct with CoraVergel Resort and save.">
    <meta property="og:image" content="https://coravergelresort.com/assets/images/11.jpg">
    <meta property="og:url" content="https://coravergelresort.com/pages/index.php">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/user.css">
    <link rel="stylesheet" href="../assets/css/contact-section.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&display=swap" rel="stylesheet">
    <style>
    /* ── Hero: "Welcome to CoraVergel Resort" ──
       Scoped here for now — move into user.css alongside the rest of the
       hero rules whenever convenient. */
    .hero {
        position: relative;
        display: flex;
        align-items: flex-end;
        justify-content: flex-start;
        min-height: 92vh;
        overflow: hidden;
    }
    .hero-bg::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(
            180deg,
            rgba(10, 22, 40, 0.55) 0%,
            rgba(10, 22, 40, 0.15) 35%,
            rgba(10, 22, 40, 0.1) 60%,
            rgba(10, 22, 40, 0.65) 100%
        );
        pointer-events: none;
    }
.hero-content {
    position: relative;
    z-index: 5;
    text-align: left;
    margin-right: auto;
    padding: 0 0 14rem 6vw;
    max-width: 720px;
    text-shadow: 0 6px 18px rgba(0, 0, 0, 0.55);
}
    .hero-script {
        display: block;
        font-family: 'Alex Brush', cursive;
        font-size: clamp(2.2rem, 4vw, 3.2rem);
        color: #c9a84c;
        line-height: 1;
        margin-bottom: 0.25rem;
    }
    .hero-title {
        font-family: 'Cormorant Garamond', serif;
        font-weight: 500;
        color: #f8f5ee;
        font-size: clamp(3.4rem, 7vw, 6.2rem);
        line-height: 1.02;
        margin: 0 0 1.4rem;
        letter-spacing: 0.01em;
    }
    .hero-tagline {
        font-family: 'DM Sans', sans-serif;
        font-weight: 500;
        color: #f8f5ee;
        font-size: 0.95rem;
        letter-spacing: 0.3em;
        text-transform: uppercase;
        padding-bottom: 1.4rem;
        margin-bottom: 1.8rem;
        border-bottom: 2px solid #c9a84c;
        display: inline-block;
    }
 @media (max-width: 640px) {
    .hero { min-height: 100vh; align-items: flex-end; }
    .hero-content { padding: 0 6vw 13rem; max-width: 100%; }  /* was 10rem */
}
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
            <a href="index.php#contact">contact</a>
        </div>
    </div>
 
    <a href="index.php" class="navbar-brand">
        <div class="custom-logo">
            <!-- swap src to your actual logo -->
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
 
</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
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
        <span class="hero-script">Welcome to</span>
        <h1 class="hero-title">CoraVergel<br>Resort</h1>
        <div class="hero-tagline">Escape. Relax. Experience.</div>
    </div>
</section>

 <section id="booking-section" class="floating-booking-wrap">
    <div class="fbb-card" id="bbarWrap">
 
      <div class="fbb-row">
        <div class="fbb-field" id="dateField">
          <span class="flbl">Date<span class="req">*</span></span>
          <div class="fval date-range-fval">
            <i class="fa-solid fa-calendar-days"></i>
            <input type="text" value="" id="dateRangeInput" class="fval-text" placeholder="Select Date Range" aria-required="true" readonly autocomplete="on" aria-invalid="false"> 
          </div>
          <div class="ferr" id="dateErr">Please select your check-in and check-out dates.</div>
        </div>
        <div class="fbb-field" id="guestField" onclick="toggleGuests(event)">
          <div class="flbl">Guests</div>
          <div class="fval">
            <span id="guestDisplay" class="fval-text">1 Room, 1 Adult, 0 Child</span>
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
            <div class="guest-child-note" role="note" aria-label="Children entrance fee information">
              <div class="guest-child-note-icon" aria-hidden="true">i</div>
              <div><strong>Note:</strong> Children 4 years old and below are <strong>FREE of entrance fee.</strong></div>
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
<!-- ══════════════════════════════════════════════════════════════
     ROOM SHOWCASE SECTION — replace the existing
     <section class="rshowcase-section"> ... </section> block
     in index.php with everything below.
═══════════════════════════════════════════════════════════════ -->
<section class="rshowcase-section">
    <div class="rsc-top-tabs">
        <button class="rsc-top-tab active" onclick="switchRoom(0)">ROOMS</button>
        <button class="rsc-top-tab" onclick="switchRoom(1)">COTTAGES</button>
    </div>

    <!-- Rooms panel -->
    <div class="rshowcase-panel active" id="rsp-0">
        <div class="rshowcase-cards" id="rsc-0">
            <div class="rshowcase-card active">
                <img class="rsc-bg-img" src="../assets/images/duplex-room.jpg" alt="Duplex Room">
                <div class="rsc-overlay"></div>
                <div class="rsc-headline">Comfort<br>Beyond<br>Compare</div>
                <div class="rsc-info">
                    <div class="rsc-info-name">Duplex Room</div>
                    <div class="rsc-info-desc">Air-conditioned duplex with free swimming &amp; entrance. Perfect for couples or small groups.</div>
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
                    <div class="rsc-info-desc">Poolside day-use pavilion for big gatherings. Cool shade, great vibes, right by the swimming pool.</div>
                    <div class="rsc-tags"><span>Day Use</span><span>Near Pool</span><span>Group Friendly</span></div>
                    <a href="rooms.php" class="rsc-cta">EXPLORE COTTAGES</a>
                </div>
            </div>
        </div>
    </div>
</section>
  <!-- Place this AFTER the whole </section> that closes rshowcase-section, NOT between the panels -->
<div class="cv-discover-wrap">
    <h2 class="cv-discover-title">Discover <em>CoraVergel Resort</em> Map</h2>
    <a href="../assets/documents/CoraVergel-Resort-Discovery-Map.pdf" target="_blank" rel="noopener noreferrer" class="cv-discovery-btn">
    CoraVergel Resort Discovery Map
</a>
</div>
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
            </div>
            <div class="cv-gal-rb cv-tile" data-src="../assets/images/11.jpg" data-caption="Swimming Pool">
                <img src="../assets/images/11.jpg" alt="Swimming Pool">
            </div>
        </div>
    </div>
</section>
<div class="cta-banner" style="cursor:pointer;" onclick="smoothScrollTo('booking-section')">
    <h2>Don't Miss Out on These Deals</h2>
    <a href="deals.php"class="cta-btn">
        <i class="fa-solid fa-calendar-check"></i>
        DEALS &amp; OFFERS
    </a>
</div>
<section id="contact">
  <div class="cv-contact-wrap">
    <div class="cvc-simple-wrap">

      <div class="cvc-form-panel">
        <div class="cvc-simple-header">
            <h3 class="cvc-title">Contact us</h3>
        </div>

        <?php if ($contact_success): ?>
        <div class="cvc-alert success"><i class="fa-solid fa-circle-check"></i> <?= $contact_success ?></div>
        <?php endif; ?>
        <?php if ($contact_error): ?>
        <div class="cvc-alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($contact_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php#contact" id="contactForm">
            <?= csrfField() ?>
          <input type="hidden" name="action" value="contact">
          <div class="cvc-field">
            <label>Email</label>
            <input type="email" name="email" placeholder="Enter email" required>
          </div>
          <div class="cvc-field">
            <label class="cvc-lbl-gold">Message</label>
            <textarea name="message" rows="4" placeholder="Tell us more about your inquiry..." required></textarea>
          </div>
          <button type="submit" class="cvc-send">
            Send Message
          </button>
        </form>
      </div>

    </div>
  </div>
</section>
<!-- POLICY ACKNOWLEDGMENT BOX -->
<aside class="cv-policy-ack" id="cvPolicyAck" aria-label="Resort policy acknowledgment">
    <div class="cv-policy-ack-head">
        <div>
            <h3>Resort Policies</h3>
            <p>Important reminders before your stay.</p>
        </div>
    </div>

    <div class="cv-policy-highlights" aria-label="Important resort rules">
        <div class="cv-policy-highlight"><i class="fa-solid fa-bowl-food"></i><span>No food corkage.</span></div>
        <div class="cv-policy-highlight"><i class="fa-solid fa-dice"></i><span>Gambling is prohibited.</span></div>
        <div class="cv-policy-highlight"><i class="fa-solid fa-paw"></i><span>Pets are not allowed.</span></div>
        <div class="cv-policy-highlight"><i class="fa-solid fa-person-swimming"></i><span>Proper swimming attire is required.</span></div>
        <div class="cv-policy-highlight"><i class="fa-solid fa-utensils"></i><span>Please bring your own utensils and cooking ware.</span></div>
    </div>

    <div class="cv-policy-ack-check">
        <input type="checkbox" id="cvPolicyAckAgree">
        <label for="cvPolicyAckAgree">
            I have read and understood the
            <button type="button" class="cv-policy-inline-link" id="cvRulesOpen">Resort Policies</button>.
        </label>
    </div>

    <button type="button" class="cv-policy-confirm-btn" id="cvPolicyConfirm" disabled>
        Confirmed
    </button>
</aside>

<!-- RESORT POLICIES MODAL -->
<div class="cv-rules-modal" id="cvRulesModal" aria-hidden="true">
    <div class="cv-rules-box" role="dialog" aria-modal="true" aria-labelledby="cvRulesTitle">
        <div class="cv-rules-header">
            <div class="cv-rules-header-left">
                <div class="cv-rules-header-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div>
                    <h2 id="cvRulesTitle">Resort Rules &amp; Policies</h2>
                    <p>Please review the following information before your visit or reservation.</p>
                </div>
            </div>
            <button type="button" class="cv-rules-close" id="cvRulesClose" aria-label="Close Resort Rules">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="cv-rules-content">
            <div class="cv-rules-grid">

                <section class="cv-policy-card">
                    <div class="cv-policy-title">
                        <div class="cv-policy-icon"><i class="fa-solid fa-paw"></i></div>
                        <h3>Pet Policy</h3>
                    </div>
                    <ul>
                        <li>We understand how important your pets are, but unfortunately, we can't accommodate pets at this time.</li>
                        <li>Violators will be asked to leave and will receive no refund.</li>
                        <li>We hope you'll consider these factors before selecting a resort that allows pets as part of their policy.</li>
                    </ul>
                </section>

                <section class="cv-policy-card">
                    <div class="cv-policy-title">
                        <div class="cv-policy-icon"><i class="fa-solid fa-receipt"></i></div>
                        <h3>Corkage</h3>
                    </div>
                    <div class="cv-policy-subgroup">
                        <strong>Softdrinks / Sparkling / Non-Alcoholic</strong>
                        <p>₱150 / case</p>
                        <p>₱50 / bottle</p>
                    </div>
                    <div class="cv-policy-subgroup">
                        <strong>Alcoholic Drinks</strong>
                        <p>₱400 / case</p>
                        <p>₱150 / bottle (750ml and above)</p>
                    </div>
                    <div class="cv-policy-subgroup">
                        <strong>Appliance</strong>
                        <p>Heater, rice cooker, water dispenser, etc. — ₱100 to ₱500</p>
                    </div>
                </section>

                <section class="cv-policy-card">
                    <div class="cv-policy-title">
                        <div class="cv-policy-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                        <h3>Important Reminders</h3>
                    </div>
                    <ul>
                        <li>No food corkage.</li>
                        <li>Gambling is prohibited.</li>
                        <li>Pets are not allowed.</li>
                        <li>Proper swimming attire is required.</li>
                        <li>We do not provide utensils and cooking ware. Please bring your own.</li>
                        <li>Speakers are allowed, but please be considerate of neighboring cottages.</li>
                        <li>For large events or parties, we recommend renting our Kubo, Pavilion, or an entire Large Gazebo for privacy.</li>
                    </ul>
                </section>

                <section class="cv-policy-card">
                    <div class="cv-policy-title">
                        <div class="cv-policy-icon"><i class="fa-solid fa-clock"></i></div>
                        <h3>Standard Time</h3>
                    </div>
                    <div class="cv-time-list">
                        <div><strong>Day Stay</strong><span>Check-in: 8:00 AM</span><span>Check-out: 4:00 PM</span></div>
                        <div><strong>Night Stay <em>(with reservations only)</em></strong><span>Check-in: 5:00 PM</span><span>Check-out: 12:00 MN</span></div>
                        <div><strong>Overnight</strong><span>Check-in: 2:00 PM</span><span>Check-out: 12:00 NN next day</span></div>
                    </div>
                    <div class="cv-policy-note">
                        <p><strong>Early check-in and late check-out charges:</strong> ₱150 per hour for fan rooms and ₱250 per hour for air-conditioned rooms, subject to room/cottage availability.</p>
                        <p>Please note that our customer service team will not be available after 8 PM.</p>
                        <p>Weekdays: 1 pool access. Weekend/Holiday: 2 pool access.</p>
                        <p>The resort reserves the right to cancel reservations for guests who do not comply with our policies and guidelines.</p>
                    </div>
                </section>

                <section class="cv-policy-card cv-policy-card-wide">
                    <div class="cv-policy-title">
                        <div class="cv-policy-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                        <h3>Guest Guidelines Before Booking</h3>
                    </div>
                    <ol class="cv-guidelines-list">
                        <li>Guests must rent one of our accommodations.</li>
                        <li>Occupancy and room service: Room capacity shall be strictly observed. An additional amount shall be charged in excess of maximum occupancy.</li>
                        <li>Cottage &amp; Venues: Please occupy only the assigned cottage you have paid for. Capacity is strictly followed.</li>
                        <li>Entrance and swimming fee are separate from accommodation fee.</li>
                        <li>Strictly proper swimming attire is a must.</li>
                        <li>Rates do not cover insurance of any sort, so guests are advised to take precautions. The management is not liable for any accident or injury.</li>
                        <li>We have no restaurant inside the resort, so bringing food is allowed without corkage fee. Corkage fee applies on drinks only.</li>
                        <li>Reservation hours are from 8 AM to 9 PM daily.</li>
                        <li>Quiet hours are from 10:00 PM to 7:00 AM.</li>
                        <li>Bluetooth speakers are allowed but must not exceed 12 inches in size.</li>
                    </ol>
                    <p class="cv-rates-note">*Rates might change on holidays without prior notice.</p>
                </section>
            </div>
        </div>
    </div>
</div>

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
                <a href="https://www.tiktok.com/@coravergel.resort" aria-label="TikTok" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-links">
        <div class="footer-col">
            <h4>About CoraVergel Resort</h4>
            <a href="about.php">About</a>
            <a href="#contact" onclick="smoothScroll(event,'contact')">Contact Us</a>
        </div>
        <div class="footer-col">
            <h4>Stay</h4>
            <a href="rooms.php">Duplex Rooms</a>
            <a href="rooms.php">Family Rooms</a>
            <a href="rooms.php">Small Bahay Kubo</a>
            <a href="rooms.php">Large Bahay Kubo</a>
        </div>
        <div class="footer-col footer-contact-col">
            <h4>Contact Information</h4>
            <a href="mailto:coravergelresort@gmail.com" class="topbar-link">coravergelresort@gmail.com</a>
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
            <a href="resort_policies.php">Resort Policy</a>
            <a href="#">Terms of Use</a>
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
        document.getElementById('dateField').classList.add('fbb-field-active');
    },

    onClose: function () {
        document.getElementById('dateField').classList.remove('fbb-field-active');
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

/* Each panel now shows a single fixed image, so the room/cottage
   carousel controls (rsc-nav, buildDots/goToCard/shiftCard) have been
   removed — nothing left to cycle between. */

/* ── Guests ──
   FIX: previously there were TWO separate copies of this block — one using
   a `gs` object, one using a `state` object — each redefining adj(),
   toggleGuests(), and goToBooking(). Since later function declarations
   silently overwrite earlier ones in JS, the on-screen counters were
   updating `state` while goToBooking() (the last definition) read guest
   totals from the untouched `gs` object, which always stayed at its
   default (1 adult, 0 children). That's why your room/guest picks never
   made it into the booking URL. Now there's a single object, `gs`, used
   everywhere. */
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
    updateGuestDisplay();
}

function updateGuestDisplay() {
    document.getElementById('guestDisplay').textContent =
        gs.rooms    + ' Room'  + (gs.rooms    > 1 ? ''   : '') + ', ' +
        gs.adults   + ' Adult' + (gs.adults   > 1 ? ''   : '') + ', ' +
        gs.children + ' Child' + (gs.children > 1 ? '' : '');
}

function applyGuests() {
    updateGuestDisplay();
    document.getElementById('guestPopup').classList.remove('open');
}

document.addEventListener('click', () => {
    document.getElementById('guestPopup').classList.remove('open');
});

/* ── Book Now ──
   Resort policy acknowledgment is handled ONLY by the floating policy box.
   Booking actions must never reopen the policy modal. */
function goToBooking() {
    proceedToBooking();
}

/* ── Called only after the guest checks the policy box and clicks "I UNDERSTAND" ── */
function proceedToBooking() {
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
        guests   : totalGuests,
        adults   : gs.adults,
        children : gs.children,
        rooms    : gs.rooms
    });

    const urlRoom = new URLSearchParams(window.location.search).get('room');
    if (urlRoom) params.set('room', urlRoom);

    window.location.href = 'rooms.php?' + params.toString();
}

/* ── Resort Rules & Policies modal ── */
(function () {
    const rulesModal = document.getElementById('cvRulesModal');
    const rulesOpen = document.getElementById('cvRulesOpen');
    const rulesClose = document.getElementById('cvRulesClose');
    const rulesOk = document.getElementById('cvRulesOk');
    const rulesAgree = document.getElementById('cvRulesAgree');
    const ack = document.getElementById('cvPolicyAckAgree');

    if (!rulesModal) return;

    function openRulesModal() {
        if (rulesAgree && rulesOk) {
            rulesAgree.checked = !!(ack && ack.checked);
            rulesOk.disabled = !rulesAgree.checked;
        }
        rulesModal.classList.add('open');
        rulesModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeRulesModal() {
        rulesModal.classList.remove('open');
        rulesModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }


    // The highlighted “Resort Policies” text in the small box opens the full policy reader.
    if (rulesOpen) {
        rulesOpen.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openRulesModal();
        });
    }

    if (rulesClose) {
        rulesClose.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeRulesModal();
        });
    }

    if (rulesAgree && rulesOk) {
        rulesAgree.addEventListener('change', function () {
            rulesOk.disabled = !rulesAgree.checked;
        });
    }

    if (rulesOk) {
        rulesOk.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!rulesAgree || !rulesAgree.checked) return;

            if (ack) {
                ack.checked = true;
                try { localStorage.setItem('cvPolicyAcknowledged', 'true'); } catch (err) {}
            }

            closeRulesModal();
        });
    }

    rulesModal.addEventListener('click', function (e) {
        if (e.target === rulesModal) closeRulesModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && rulesModal.classList.contains('open')) closeRulesModal();
    });
})();

/* ── Small policy acknowledgment box ── */
(function () {
    const policyBox = document.getElementById('cvPolicyAck');
    const ack = document.getElementById('cvPolicyAckAgree');
    const confirmBtn = document.getElementById('cvPolicyConfirm');
    if (!policyBox || !ack || !confirmBtn) return;

    // Show again whenever the page is loaded or reloaded.
    confirmBtn.disabled = true;

    ack.addEventListener('change', function () {
        confirmBtn.disabled = !ack.checked;
    });

    confirmBtn.addEventListener('click', function () {
        if (!ack.checked) return;

        confirmBtn.innerHTML = 'Confirmed';
        confirmBtn.classList.add('is-confirmed');

        policyBox.style.opacity = '0';
        policyBox.style.transform = 'translateY(20px)';
        policyBox.style.pointerEvents = 'none';

        setTimeout(function () {
            policyBox.style.display = 'none';
        }, 300);
    });
})();

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

</body>
</html>