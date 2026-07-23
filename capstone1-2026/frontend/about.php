<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/index.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<!-- TOPBAR -->
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
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">        <!-- Hamburger — only visible on mobile via CSS -->
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
        <a href="../user/login.php" class="login-btn">
            <i class="fa-regular fa-user"></i>
        </a>
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
<div class="about-hero">
    <img class="hero-bg-img" src="../assets/images/background.jpg"
         alt="CoraVergel Resort" onerror="this.style.display='none'">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <div class="hero-eyebrow">
            <span class="hero-dot"></span>
            CoraVergel Resort
            <span class="hero-dot"></span>
        </div>
        <h1>Our Story</h1>
        <p>Where family, nature, and Filipino hospitality come together</p>
        <div class="hero-divider"></div>
    </div>
</div>

<!-- ══════════ OUR STORY ══════════ -->
<section class="about-section">
    <div class="story-grid">

        <div class="story-img-wrap">
            <img src="../assets/images/about-image.jpg" alt="CoraVergel Resort">
            <div class="story-img-accent"></div>
            <div class="story-badge">Est. Tigbauan, Iloilo</div>
        </div>

        <div class="story-text">
            <div class="section-eyebrow">Our Story</div>
            <h2 class="section-title">A Place Born from<br><em>Love &amp; Family</em></h2>
            <p class="section-body">
                CoraVergel Resort was born from a simple dream — to create a place where families could
                slow down, reconnect, and enjoy the natural beauty of Iloilo. Nestled in the quiet barangay
                of Barosong in Tigbauan, the resort offers a peaceful escape just a short drive from the city.
            </p>
            <p class="section-body" style="margin-top:16px;">
                What started as a family property has grown into a beloved destination for locals and visitors
                alike — a place where the sound of children splashing in the pool, the warmth of Filipino
                hospitality, and the rustic charm of bahay kubo-style living all come together.
            </p>
            <p class="section-body" style="margin-top:16px;">
                Whether you're planning a romantic overnight stay, a family reunion, a barkada trip, or simply
                a relaxing day by the pool — CoraVergel is ready to welcome you.
            </p>
        </div>

    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-cell">
            <div class="stat-num">4</div>
            <div class="stat-label">Room Types</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">5</div>
            <div class="stat-label">Cottage Options</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">3</div>
            <div class="stat-label">Tent Packages</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">∞</div>
            <div class="stat-label">Memories Made</div>
        </div>
    </div>
</section>

<!-- ══════════ WHAT WE OFFER ══════════ -->
<div class="about-section--alt">
<section class="about-section" style="padding-top:64px;padding-bottom:64px;">
    <div style="text-align:center;margin-bottom:8px;">
        <div class="section-eyebrow" style="justify-content:center;">What We Offer</div>
        <h2 class="section-title" style="text-align:center;">Everything You Need<br><em>for the Perfect Getaway</em></h2>
    </div>

    <div class="offerings-grid">
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-person-swimming"></i></div>
            <div class="offering-name">Pool &amp; Swimming</div>
            <div class="offering-desc">Enjoy our resort's refreshing swimming pool — free for all overnight guests. Cool off, splash around, and make memories with the whole family.</div>
        </div>
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-moon"></i></div>
            <div class="offering-name">Overnight Rooms</div>
            <div class="offering-desc">Choose from air-conditioned Duplex Rooms, spacious Family Rooms — all with free pool access and resort entrance included.</div>
        </div>
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-house"></i></div>
            <div class="offering-name">Bahay Kubo Experience</div>
            <div class="offering-desc">Sleep under native-style roofing surrounded by nature. Our Small and Large Bahay Kubo units offer an authentic Filipino countryside feel.</div>
        </div>
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-campground"></i></div>
            <div class="offering-name">Tent Camping</div>
            <div class="offering-desc">Spend the night under the stars with our Premium Tent rentals. Complete with mattress, pillows, and blankets — check-in at 5PM, check-out at 7AM.</div>
        </div>
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-umbrella-beach"></i></div>
            <div class="offering-name">Cottages &amp; Gazebos</div>
            <div class="offering-desc">Perfect for day trips and celebrations. Choose from large and small gazebos, kubo cottages, or open umbrellas — accommodating groups of all sizes.</div>
        </div>
        <div class="offering-card">
            <div class="offering-icon"><i class="fa-solid fa-utensils"></i></div>
            <div class="offering-name">On-Site Dining</div>
            <div class="offering-desc">Resort food and beverages are available on-site. Savor local flavors without leaving the property — no need to bring outside food.</div>
        </div>
    </div>
</section>
</div>

<!-- ══════════ WHY CHOOSE US ══════════ -->
<div class="about-section--dark">
<section class="about-section" style="padding-top:64px;padding-bottom:64px;">
    <div class="inner">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:start;" class="why-split">
            <div>
                <div class="section-eyebrow">Why CoraVergel</div>
                <h2 class="section-title section-title--light">Reasons Guests<br><em>Keep Coming Back</em></h2>
                <p class="section-body section-body--light" style="margin-top:8px;">
                    We're not just a resort — we're a home away from home. Every detail is designed with
                    your comfort and happiness in mind.
                </p>
            </div>
            <div class="why-grid">
                <div class="why-item">
                    <div class="why-num">01</div>
                    <div class="why-text">
                        <h4>Authentic Filipino Atmosphere</h4>
                        <p>From the bahay kubo accommodations to the warm, personal service — every corner reflects the best of Filipino hospitality.</p>
                    </div>
                </div>
                <div class="why-item">
                    <div class="why-num">02</div>
                    <div class="why-text">
                        <h4>Family-Friendly Environment</h4>
                        <p>Safe, clean, and welcoming for all ages. We take pride in being a place where families create lifelong memories.</p>
                    </div>
                </div>
                <div class="why-item">
                    <div class="why-num">03</div>
                    <div class="why-text">
                        <h4>Affordable Rates</h4>
                        <p>Premium resort experience without the premium price tag. Our rates are designed to be accessible for every Filipino family.</p>
                    </div>
                </div>
                <div class="why-item">
                    <div class="why-num">04</div>
                    <div class="why-text">
                        <h4>Peaceful Nature Setting</h4>
                        <p>Escape the city noise. Surrounded by greenery in quiet Tigbauan, our resort offers a true breath of fresh air.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
</div>

<!-- ══════════ VISIT US ══════════ -->
<section class="about-section">
    <div class="visit-grid">
        <div class="visit-info">
            <div class="section-eyebrow">Find Us</div>
            <h2 class="section-title">Plan Your Visit</h2>
            <p class="section-body" style="margin-bottom:32px;">
                We're located in the peaceful municipality of Tigbauan, Iloilo — roughly 20–25 minutes
                from Iloilo City proper. Easy to find, and worth every kilometer.
            </p>

            <div class="visit-detail">
                <div class="visit-detail-icon"><i class="fa-solid fa-location-dot"></i></div>
                <div class="visit-detail-text">
                    <strong>Address</strong>
                    <span>21 Barosong, Tigbauan, Iloilo City, Philippines</span>
                </div>
            </div>
            <div class="visit-detail">
                <div class="visit-detail-icon"><i class="fa-solid fa-phone"></i></div>
                <div class="visit-detail-text">
                    <strong>Phone</strong>
                    <span>+320 2512</span>
                </div>
            </div>
            <div class="visit-detail">
                <div class="visit-detail-icon"><i class="fa-regular fa-envelope"></i></div>
                <div class="visit-detail-text">
                    <strong>Email</strong>
                    <span>coravergelresort@gmail.com</span>
                </div>
            </div>
            <div class="visit-detail">
                <div class="visit-detail-icon"><i class="fa-solid fa-clock"></i></div>
                <div class="visit-detail-text">
                    <strong>Check-in / Check-out</strong>
                    <span>Check-in: 2:00 PM &nbsp;·&nbsp; Check-out: 12:00 PM</span>
                </div>
            </div>
            <div class="visit-detail">
                <div class="visit-detail-icon"><i class="fa-brands fa-facebook-f"></i></div>
                <div class="visit-detail-text">
                    <strong>Facebook</strong>
                    <span><a href="https://www.facebook.com/coravergelresort" target="_blank" rel="noopener noreferrer" style="color:var(--gold);text-decoration:none;">facebook.com/coravergelresort</a></span>
                </div>
            </div>
        </div>
        <div class="visit-map">
            <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3919.0!2d122.396162!3d10.714106!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTDCsDQyJzUwLjgiTiAxMjLCsDIzJzQ2LjIiRQ!5e0!3m2!1sen!2sph!4v1" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="CoraVergel Resort Location">
            </iframe>
        </div>
    </div>
</section>

<!-- ══════════ CTA BANNER ══════════ -->
<div class="cta-banner">
    <h2>Ready for a Getaway?</h2>
    <p>Book your stay at CoraVergel Resort and experience Iloilo's hidden paradise.</p>
    <a href="rooms.php" class="cta-btn">
        <i class="fa-solid fa-calendar-check"></i>
        View Rooms &amp; Rates
    </a>
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
            <a href="index.php#contact">Contact Us</a>
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
            <a href="tel:3202512" class="topbar-link">+320 2512</a>
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

<style>
    @media (max-width: 900px) {
        .why-split {
            grid-template-columns: 1fr !important;
            gap: 40px !important;
        }
    }
</style>
<script>
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