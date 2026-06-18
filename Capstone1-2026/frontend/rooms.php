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
</style>
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
<div class="rooms-hero">
    <img class="hero-bg-img" src="../assets/images/background.jpg"
         alt="Resort" onerror="this.style.display='none'">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <div class="hero-eyebrow">
            <span class="hero-dot"></span>
            CoraVergel Resort
            <span class="hero-dot"></span>
        </div>
        <h1>Rooms &amp; Rates</h1>
        <p>Discover your perfect accommodation in paradise</p>
        <div class="hero-divider"></div>
    </div>
</div>

<!-- LOGIN MODAL -->
<div class="login-alert-overlay" id="loginPromptModal">
    <div class="login-alert-box">
        <div class="login-alert-icon">
            <i class="fa-regular fa-user"></i>
        </div>
        <h3>Login Required</h3>
        <p>You need to be logged in to book a room. Please login or create an account to continue.</p>
        <div class="login-alert-btns">
            <button class="login-alert-cancel" onclick="closeLPModal()">Maybe Later</button>
            <a href="../user/login.php" class="login-alert-confirm">
                <i class="fa-regular fa-user"></i> Login Now
            </a>
        </div>
    </div>
</div>

<!-- PAGE BODY -->
<div class="page-body">

    <!-- ══════════ OVERNIGHT ROOMS ══════════ -->
    <section class="content-section">
        <div class="section-label">
            <span class="label-pill"><i class="fa-solid fa-moon"></i> Overnight Stay</span>
            <h2 class="section-heading">Accommodations</h2>
            <p class="section-sub">Unwind in comfort — every room includes free swimming &amp; resort entrance</p>
        </div>

        <div class="rooms-grid">

            <!-- CARD 1: Duplex Room -->
            <div class="room-card">
                <div class="room-img">
                    <img src="../assets/images/1.jpg" alt="Duplex Room">
                    <span class="room-badge">Overnight</span>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> 2 pax</span>
                </div>
                <div class="room-body">
                    <h3 class="room-name">Duplex Room</h3>
                    <p class="room-desc">Comfortable air-conditioned duplex room with free entrance and swimming included for overnight guests. Perfect for couples or small groups.</p>
                    <div class="room-tags">
                        <span class="room-tag">AC</span>
                        <span class="room-tag">WiFi</span>
                        <span class="room-tag">Free Swimming</span>
                        <span class="room-tag">Free Entrance</span>
                    </div>
                    <div class="room-footer">
                        <div class="room-price">
                            <span style="font-size:12px;color:#aaa;text-decoration:line-through;display:block;">₱3,200/night</span>
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt">2,880</span>
                            <span class="rp-per">/night</span>
                        </div>                        
                        <button class="btn-book"
                            onclick="openModal('Duplex Room','₱2,880 / night','../assets/images/standard_room.jpg')">
                            Book Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- CARD 2: Family Room -->
            <div class="room-card">
                <div class="room-img">
                    <img src="../assets/images/family_room.jpg" alt="Family Room"
                         onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
                    <span class="room-badge">Best for Families</span>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> 7 pax</span>
                </div>
                <div class="room-body">
                    <h3 class="room-name">Family Room</h3>
                    <p class="room-desc">Spacious family room perfect for larger groups with free entrance and swimming for all overnight guests. Ideal for family reunions and celebrations.</p>
                    <div class="room-tags">
                        <span class="room-tag">AC</span>
                        <span class="room-tag">WiFi</span>
                        <span class="room-tag">Free Swimming</span>
                        <span class="room-tag">Free Entrance</span>
                        <span class="room-tag">Large Space</span>
                    </div>
                    <div class="room-footer">
                        <div class="room-price">
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt">6,000</span>
                            <span class="rp-per">/night</span>
                        </div>
                        <button class="btn-book"
                            onclick="openModal('Family Room','₱6,000 / night','../assets/images/family_room.jpg')">
                            Book Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- CARD 3: Small Bahay Kubo -->
            <div class="room-card">
                <div class="room-img">
                    <img src="../assets/images/small_bahay_kubo.jpg" alt="Small Bahay Kubo"
                         onerror="this.src='https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=800&q=80'">
                    <span class="room-badge">Overnight</span>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> 4 pax</span>
                </div>
                <div class="room-body">
                    <h3 class="room-name">Small Bahay Kubo</h3>
                    <p class="room-desc">Cozy traditional Bahay Kubo-style accommodation with a relaxed and natural atmosphere. Perfect for guests who love nature and a rustic getaway.</p>
                    <div class="room-tags">
                        <span class="room-tag">Free Swimming</span>
                        <span class="room-tag">Free Entrance</span>
                        <span class="room-tag">Nature View</span>
                    </div>
                    <div class="room-footer">
                        <div class="room-price">
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt">2,100</span>
                            <span class="rp-per">/night</span>
                        </div>
                        <button class="btn-book"
                            onclick="openModal('Small Bahay Kubo','₱2,100 / night','../assets/images/small_bahay_kubo.jpg')">
                            Book Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- CARD 4: Large Bahay Kubo -->
            <div class="room-card">
                <div class="room-img">
                    <img src="../assets/images/large_bahay_kubo.jpg" alt="Large Bahay Kubo"
                         onerror="this.src='https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=800&q=80'">
                    <span class="room-badge">Popular</span>
                    <span class="room-cap-badge"><i class="fa-solid fa-user-group"></i> 6 pax</span>
                </div>
                <div class="room-body">
                    <h3 class="room-name">Large Bahay Kubo</h3>
                    <p class="room-desc">Spacious traditional Bahay Kubo perfect for groups, surrounded by beautiful resort grounds and natural scenery. Great for barkadas and small gatherings.</p>
                    <div class="room-tags">
                        <span class="room-tag">Free Swimming</span>
                        <span class="room-tag">Free Entrance</span>
                        <span class="room-tag">Nature View</span>
                        <span class="room-tag">Large Space</span>
                    </div>
                    <div class="room-footer">
                        <div class="room-price">
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt">3,200</span>
                            <span class="rp-per">/night</span>
                        </div>
                        <button class="btn-book"
                            onclick="openModal('Large Bahay Kubo','₱3,200 / night','../assets/images/large_bahay_kubo.jpg')">
                            Book Now
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- ══════════ COTTAGES ══════════ -->
    <section class="content-section content-section--alt">
        <div class="section-inner">
            <div class="section-label">
                <span class="label-pill label-pill--gold"><i class="fa-solid fa-sun"></i> Day Use</span>
                <h2 class="section-heading">Cottages &amp; Gazebos</h2>
                <p class="section-sub">Perfect for day trips, family gatherings, and celebrations by the pool</p>
            </div>
            <div class="small-cards-grid">
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid fa-umbrella-beach"></i></div>
                    <div>
                        <div class="sc-name">Large Gazebo</div>
                        <div class="sc-sub">Near swimming pool</div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to 15 pax</div>
                    </div>
                    <div class="sc-price"><span class="scp-sym">₱</span><span class="scp-amt">1,500</span></div>
                </div>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid fa-umbrella-beach"></i></div>
                    <div>
                        <div class="sc-name">Small Gazebo</div>
                        <div class="sc-sub">Near swimming pool</div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to 8 pax</div>
                    </div>
                    <div class="sc-price"><span class="scp-sym">₱</span><span class="scp-amt">1,200</span></div>
                </div>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid fa-umbrella"></i></div>
                    <div>
                        <div class="sc-name">Umbrella</div>
                        <div class="sc-sub">Open area</div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to 4 pax</div>
                    </div>
                    <div class="sc-price"><span class="scp-sym">₱</span><span class="scp-amt">400</span></div>
                </div>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid fa-house"></i></div>
                    <div>
                        <div class="sc-name">Small Kubo</div>
                        <div class="sc-sub">Shaded cottage</div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to 10 pax</div>
                    </div>
                    <div class="sc-price"><span class="scp-sym">₱</span><span class="scp-amt">1,000</span></div>
                </div>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid fa-house"></i></div>
                    <div>
                        <div class="sc-name">Large Kubo</div>
                        <div class="sc-sub">Great for big groups</div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to 20 pax</div>
                    </div>
                    <div class="sc-price"><span class="scp-sym">₱</span><span class="scp-amt">2,000</span></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════ TENTS ══════════ -->
    <section class="content-section">
        <div class="section-label">
            <span class="label-pill label-pill--dark"><i class="fa-solid fa-campground"></i> Tent Rental</span>
            <h2 class="section-heading">Premium Tents</h2>
            <p class="section-sub">Check-in 5PM · Check-out 7AM · Includes mattress, pillows &amp; blankets</p>
        </div>
        <div class="tent-cards-grid">
            <div class="tent-card">
                <div class="tent-icon"><i class="fa-solid fa-campground"></i></div>
                <div class="tent-body">
                    <div class="tent-name">Premium Tent A</div>
                    <div class="tent-meta">
                        <i class="fa-solid fa-user-group"></i> 2 pax
                        <span class="tent-sep">·</span> 6 units available
                    </div>
                    <div class="tent-includes">
                        <i class="fa-solid fa-check"></i> Free Entrance
                        <span class="tent-sep">·</span>
                        <i class="fa-solid fa-check"></i> Bedding Included
                    </div>
                </div>
                <div class="tent-price">
                    <span class="tp-sym">₱</span><span class="tp-amt">900</span>
                    <span class="tp-night">/night</span>
                </div>
            </div>
            <div class="tent-card">
                <div class="tent-icon"><i class="fa-solid fa-campground"></i></div>
                <div class="tent-body">
                    <div class="tent-name">Premium Tent B</div>
                    <div class="tent-meta">
                        <i class="fa-solid fa-user-group"></i> 3 pax
                        <span class="tent-sep">·</span> 1 unit available
                    </div>
                    <div class="tent-includes">
                        <i class="fa-solid fa-check"></i> Free Entrance
                        <span class="tent-sep">·</span>
                        <i class="fa-solid fa-check"></i> Bedding Included
                    </div>
                </div>
                <div class="tent-price">
                    <span class="tp-sym">₱</span><span class="tp-amt">1,100</span>
                    <span class="tp-night">/night</span>
                </div>
            </div>
            <div class="tent-card">
                <div class="tent-icon"><i class="fa-solid fa-campground"></i></div>
                <div class="tent-body">
                    <div class="tent-name">Premium Tent C</div>
                    <div class="tent-meta">
                        <i class="fa-solid fa-user-group"></i> 6 pax
                        <span class="tent-sep">·</span> 1 unit available
                    </div>
                    <div class="tent-includes">
                        <i class="fa-solid fa-check"></i> Free Entrance
                        <span class="tent-sep">·</span>
                        <i class="fa-solid fa-check"></i> Bedding Included
                    </div>
                </div>
                <div class="tent-price">
                    <span class="tp-sym">₱</span><span class="tp-amt">2,300</span>
                    <span class="tp-night">/night</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════ REMINDERS ══════════ -->
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

<script>
function openModal(roomName, roomPrice, roomImg) {
    // Show the login prompt modal
    document.getElementById('loginPromptModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLPModal() {
    document.getElementById('loginPromptModal').classList.remove('open');
    document.body.style.overflow = '';
}

// Close on overlay click
document.getElementById('loginPromptModal').addEventListener('click', function(e) {
    if (e.target === this) closeLPModal();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLPModal();
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