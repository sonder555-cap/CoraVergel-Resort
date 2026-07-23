<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deals &amp; Special Offers – CoraVergel Resort</title>
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
<div class="deals-hero">
    <img class="hero-bg-img" src="../assets/images/background.jpg"
         alt="CoraVergel Deals" onerror="this.style.display='none'">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <div class="hero-eyebrow">
            <span class="hero-dot"></span>
            CoraVergel Resort
            <span class="hero-dot"></span>
        </div>
        <h1>Deals &amp; Special Offers</h1>
        <p>Exclusive packages designed to make your stay more memorable — and more affordable</p>
        <div class="hero-divider"></div>
    </div>
</div>

<div class="page-body">

    <!-- ══ FEATURED DEAL ══ -->
    <section class="deals-section">
        <div class="section-eyebrow">Best Value</div>
        <h2 class="section-title">Featured Package</h2>
        <p class="section-sub">Our most popular deal — great for families and barkadas looking for the full resort experience.</p>

<div class="featured-deal">
    <div class="featured-deal-img">
        <img src="../assets/images/.jpg" alt="Duplex Room Deal"
             onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
        <div class="featured-deal-ribbon"><i class="fa-solid fa-star"></i> &nbsp;Best Deal</div>
    </div>
    <div class="featured-deal-body">
        <div class="deal-tag"><i class="fa-solid fa-heart"></i> Couples &amp; Small Groups</div>
        <h3>Duplex Room — Overnight Stay</h3>
        <p>Our most loved accommodation in Tigbauan, Iloilo. The air-conditioned Duplex Room is perfect for couples or small groups looking for a comfortable overnight escape — free swimming and resort entrance included.</p>
        <div class="deal-includes">
            <span class="deal-include-pill"><i class="fa-solid fa-check"></i> Duplex Room (1 night)</span>
            <span class="deal-include-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
            <span class="deal-include-pill"><i class="fa-solid fa-check"></i> Free Entrance (2 pax)</span>
            <span class="deal-include-pill"><i class="fa-solid fa-check"></i> Air-Conditioning</span>
            <span class="deal-include-pill"><i class="fa-solid fa-check"></i> Free WiFi</span>
        </div>
        <div class="featured-deal-footer">
            <div class="deal-price-wrap">
                <span class="deal-price-label">Price per night</span>
                <span class="deal-price">&#8369;2,880</span>
                <span class="deal-price-orig">Regular: &#8369;3,200</span>
            </div>
            <div class="deal-valid"><i class="fa-regular fa-calendar"></i> Valid weekdays &amp; weekends</div>
            <a href="rooms.php" class="btn-deal"><i class="fa-solid fa-calendar-check"></i> Book This Deal</a>
        </div>
    </div>
</div>
        <!-- MORE DEALS GRID -->
        <div class="section-eyebrow" style="margin-top:16px;">All Offers</div>
        <h2 class="section-title">More Great Deals</h2>
        <p class="section-sub">Mix and match packages to create your perfect CoraVergel experience.</p>

<div class="deals-grid">

    <!-- Barkada Weekend — Large Bahay Kubo ₱3,200 + Large Gazebo ₱1,500 -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/large_bahay_kubo.jpg" alt="Barkada Weekend"
                 onerror="this.src='https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=800&q=80'">
            <span class="deal-card-badge deal-card-badge--gold">Save &#8369;500</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-people-group"></i> Barkada Special</span>
            <div class="deal-card-name">Barkada Weekend Escape</div>
            <div class="deal-card-desc">Perfect for groups of 6. Book the Large Bahay Kubo overnight and get a complimentary Large Gazebo for day use — with free resort entrance and swimming included.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Large Bahay Kubo (1 night)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Large Gazebo (day use)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Entrance (6 pax)</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>4,200</div>
                <div class="dc-price-orig">Regular: &#8369;4,700</div>
                <div class="dc-price-sub">Up to 6 pax</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

    <!-- Couples Retreat — Duplex Room ₱3,200 -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/1.jpg" alt="Couples Retreat"
                 onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
            <span class="deal-card-badge">Romantic</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-heart"></i> Couples</span>
            <div class="deal-card-name">Romantic Overnight Retreat</div>
            <div class="deal-card-desc">A cozy overnight stay for two in our air-conditioned Duplex Room. Enjoy free swimming, WiFi, and a quiet evening by the pool — just the two of you.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Duplex Room (1 night)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Entrance (2 pax)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> AC · WiFi</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>3,200</div>
                <div class="dc-price-sub">2 pax · /night</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

    <!-- Camp & Swim — Premium Tent C ₱2,300 -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/background.jpg" alt="Camp and Swim"
                 onerror="this.src='https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=800&q=80'">
            <span class="deal-card-badge deal-card-badge--gold">Adventure</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-campground"></i> Camping</span>
            <div class="deal-card-name">Camp &amp; Swim Experience</div>
            <div class="deal-card-desc">Pitch up in our Premium Tent C for up to 6 guests — mattress, pillows, and blankets all included. Check-in 5PM, check-out 7AM, free resort entrance.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Premium Tent C (6 pax)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Mattress &amp; Bedding</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Entrance</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>2,300</div>
                <div class="dc-price-sub">Up to 6 pax · /night</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

    <!-- Stay & Dine — Duplex Room ₱3,200 + dining -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/family_room.jpg" alt="Stay and Dine"
                 onerror="this.src='https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=800&q=80'">
            <span class="deal-card-badge">Dine &amp; Stay</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-utensils"></i> Stay &amp; Dine</span>
            <div class="deal-card-name">Stay &amp; Dine Package</div>
            <div class="deal-card-desc">Stay in our Duplex Room and enjoy a set meal for two from our on-site menu. Free swimming and entrance included — the perfect all-in-one overnight package.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Duplex Room (1 night)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Set Meal for 2</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Entrance (2 pax)</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>4,000</div>
                <div class="dc-price-orig">Regular: &#8369;4,500+</div>
                <div class="dc-price-sub">2 pax · /night</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

    <!-- Family Day Trip — Large Kubo ₱2,000 -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/small_bahay_kubo.jpg" alt="Day Trip Package"
                 onerror="this.src='https://images.unsplash.com/photo-1572375992501-4b0892d50c69?w=800&q=80'">
            <span class="deal-card-badge deal-card-badge--gold">Day Use</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-sun"></i> Day Trip</span>
            <div class="deal-card-name">Family Day Trip Bundle</div>
            <div class="deal-card-desc">No overnight? No problem. Book the Large Kubo cottage for the day — pool access included for up to 20 pax. Perfect for weekend day trips from Iloilo City.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Large Kubo (day use)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Pool Access</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Up to 20 pax</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>2,000</div>
                <div class="dc-price-sub">Up to 20 pax</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

    <!-- Celebration — Large Gazebo ₱1,500 + Large Bahay Kubo ₱3,200 -->
    <div class="deal-card">
        <div class="deal-card-img">
            <img src="../assets/images/large_bahay_kubo.jpg" alt="Birthday Package"
                 onerror="this.src='https://images.unsplash.com/photo-1464349095431-e9a21285b5f3?w=800&q=80'">
            <span class="deal-card-badge">Celebration</span>
        </div>
        <div class="deal-card-body">
            <span class="deal-card-tag"><i class="fa-solid fa-cake-candles"></i> Events</span>
            <div class="deal-card-name">Birthday &amp; Celebration Deal</div>
            <div class="deal-card-desc">Planning a birthday, anniversary, or reunion? Combine a Large Gazebo (up to 15 pax) with a Large Bahay Kubo overnight (up to 6 pax) — celebrate in style with the whole crew.</div>
            <div class="deal-card-includes">
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Large Gazebo (day use, 15 pax)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Large Bahay Kubo (1 night)</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Swimming</span>
                <span class="dci-pill"><i class="fa-solid fa-check"></i> Free Entrance</span>
            </div>
        </div>
        <div class="deal-card-footer">
            <div class="dc-price">
                <div class="dc-price-main"><span class="dc-price-sym">&#8369;</span>4,200</div>
                <div class="dc-price-orig">Regular: &#8369;4,700</div>
                <div class="dc-price-sub">Great for big groups</div>
            </div>
            <a href="rooms.php" class="btn-deal btn-deal-outline">Book Now</a>
        </div>
    </div>

</div>
    </section>

    <!-- ══ SEASONAL PROMOS ══ -->
    <div class="seasonal-strip">
        <div class="inner">
            <div class="section-eyebrow" style="color:var(--gold);">Seasonal</div>
            <h2 class="section-title section-title--light" style="color:#fff;">Seasonal Promotions</h2>
            <p class="section-sub" style="color:#666;">Special discounts that change with the season — check back regularly for new offers.</p>
            <div class="seasonal-grid">
                <div class="seasonal-cell">
                    <div class="seasonal-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="seasonal-name">Summer School Break</div>
                    <div class="seasonal-desc">March to May discounts for student groups and families. Bring the class, celebrate the summer.</div>
                    <span class="seasonal-discount">Up to 10% Off</span>
                </div>
                <div class="seasonal-cell">
                    <div class="seasonal-icon"><i class="fa-solid fa-heart"></i></div>
                    <div class="seasonal-name">Valentine's Escape</div>
                    <div class="seasonal-desc">February special for couples — Duplex Room with a complimentary welcome setup and poolside access.</div>
                    <span class="seasonal-discount">Special Rate</span>
                </div>
                <div class="seasonal-cell">
                    <div class="seasonal-icon"><i class="fa-solid fa-snowflake"></i></div>
                    <div class="seasonal-name">Holiday Season Deal</div>
                    <div class="seasonal-desc">December to January packages for families celebrating the holidays together at the resort.</div>
                    <span class="seasonal-discount">Bundle Savings</span>
                </div>
                <div class="seasonal-cell">
                    <div class="seasonal-icon"><i class="fa-solid fa-flag"></i></div>
                    <div class="seasonal-name">Dinagyang Weekend</div>
                    <div class="seasonal-desc">Celebrate Iloilo's biggest festival with a resort stay — beat the city crowd and relax in Tigbauan.</div>
                    <span class="seasonal-discount">Limited Slots</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ HOW TO AVAIL ══ -->
    <section class="deals-section" style="background:#fff;">
        <div style="text-align:center;">
            <div class="section-eyebrow" style="justify-content:center;">Simple Process</div>
            <h2 class="section-title" style="text-align:center;">How to Avail a Deal</h2>
            <p class="section-sub" style="text-align:center;">Getting your special rate is quick and easy — just follow these steps.</p>
        </div>
        <div class="steps-grid">
            <div class="step-item">
                <div class="step-num">1</div>
                <div class="step-title">Choose Your Deal</div>
                <div class="step-desc">Browse our offers and pick the package that fits your group size, dates, and budget.</div>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <div class="step-title">Create an Account</div>
                <div class="step-desc">Sign up or log in to your CoraVergel account to proceed with your booking.</div>
            </div>
            <div class="step-item">
                <div class="step-num">3</div>
                <div class="step-title">Book &amp; Confirm</div>
                <div class="step-desc">Select your preferred dates and complete your reservation — easy and secure.</div>
            </div>
            <div class="step-item">
                <div class="step-num">4</div>
                <div class="step-title">Enjoy Your Stay</div>
                <div class="step-desc">Arrive at CoraVergel and let us take care of the rest. Relax — you've earned it!</div>
            </div>
        </div>

        <div class="terms-card">
            <h4><i class="fa-solid fa-circle-info"></i> Terms &amp; Conditions</h4>
            <ul class="terms-list">
                <li>All deals are subject to availability and must be booked in advance.</li>
                <li>Rates are per accommodation unit, not per person, unless stated otherwise.</li>
                <li>Packages cannot be combined with other ongoing promotions.</li>
                <li>Check-in is at 2:00 PM; Check-out is at 12:00 PM for overnight stays.</li>
                <li>Tent check-in is at 5:00 PM; Check-out is at 7:00 AM.</li>
                <li>No outside food and beverages are allowed inside the resort.</li>
                <li>Free swimming is included for all overnight guests.</li>
                <li>Deals are valid for the dates specified per seasonal promotion.</li>
                <li>CoraVergel Resort reserves the right to change or withdraw offers at any time.</li>
                <li>For inquiries, contact us at coravergelresort@gmail.com or +320 2512.</li>
            </ul>
        </div>
    </section>

    <!-- ══ CTA ══ -->
    <div class="cta-banner">
        <h2>Don't Miss Out on These Deals</h2>
        <p>Slots are limited — book your CoraVergel experience today before they're gone.</p>
        <a href="rooms.php" class="cta-btn">
            <i class="fa-solid fa-calendar-check"></i>
            View Rooms &amp; Book Now
        </a>
    </div>

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