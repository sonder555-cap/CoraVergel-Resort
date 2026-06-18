<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/index.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
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
<!-- ══════════ PAGE HEADER ══════════ -->
<div class="page-header">
    <div class="ph-inner">
        <div>
            <div class="ph-eyebrow">Resort Gallery</div>
            <div class="ph-title">Where every<br><em>moment</em> stays.</div>
        </div>
        <div>
            <div class="ph-count">
                <span>Photographs</span>
            </div>
            <p class="ph-sub">Nestled along the shores of Tigbauan, Iloilo — a curated collection of life at CoraVergel.</p>
        </div>
    </div>
</div>

<!-- ══════════ LIGHTBOX ══════════ -->
<div class="lightbox" id="lightbox">
    <button class="lb-close" id="lbClose"><i class="fa-solid fa-xmark"></i></button>
    <button class="lb-nav-btn lb-prev" id="lbPrev"><i class="fa-solid fa-chevron-left"></i></button>
    <div class="lb-img-wrap">
        <img src="" id="lbImg" alt="">
    </div>
    <div class="lb-info">
        <div class="lb-caption" id="lbCaption"></div>
        <div class="lb-counter" id="lbCounter"></div>
    </div>
    <button class="lb-nav-btn lb-next" id="lbNext"><i class="fa-solid fa-chevron-right"></i></button>
</div>

<!-- ══════════ GALLERY ══════════ -->
<div class="gallery-wrap">


    <!-- ── ROW 1 — Hero opener: large left + tall right ── -->
    <div class="gal-row-1 gal-block">
        <div class="gal-tile gal-tile--hero"
             data-src="../assets/images/1.jpg"
             data-caption="Resort Aerial View"
             data-cat="views"
             onclick="openLb(this)">
            <img src="../assets/images/1.jpg" alt="Resort Aerial View">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Aerial View</div>
                <div class="gal-tile-cat">Resort Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/background.jpg"
             data-caption="Resort Landscape"
             data-cat="views"
             onclick="openLb(this)">
            <img src="../assets/images/background.jpg" alt="Resort View">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Resort Landscape</div>
                <div class="gal-tile-cat">Views</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

    <!-- ── ROW 2 — Three columns: medium, medium, wider ── -->
    <div class="gal-row-2 gal-block">
        <div class="gal-tile"
             data-src="../assets/images/2.jpg"
             data-caption="Swimming Pool"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/2.jpg" alt="Swimming Pool">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Swimming Pool</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/11.jpg"
             data-caption="Resort Grounds"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/11.jpg" alt="Resort Grounds">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Resort Grounds</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/g7.jpg"
             data-caption="Garden View"
             data-cat="views"
             onclick="openLb(this)">
            <img src="../assets/images/g7.jpg" alt="Garden View">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Garden View</div>
                <div class="gal-tile-cat">Views</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

    <!-- ── ROW 3 — Text panel + wide image ── -->
    <div class="gal-row-3 gal-block">
        <div class="gal-text-panel">
            <div class="gtp-eyebrow">Our Story</div>
            <div class="gtp-title">A paradise crafted<br>for <em>unforgettable</em> stays</div>
            <p class="gtp-body">Every corner of CoraVergel tells a story — from the crystal-clear pools to the rustling bamboo groves. Come see it for yourself.</p>
            <a href="../user/dashboard.php#booking-section" class="gtp-btn">
                <i class="fa-solid fa-calendar-days"></i> Book Your Stay
            </a>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/12.jpg"
             data-caption="Poolside Retreat"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/12.jpg" alt="Poolside">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Poolside Retreat</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

    <!-- ── ROW 4 — Three equal columns ── -->
    <div class="gal-row-4 gal-block">
        <div class="gal-tile"
             data-src="../assets/images/g8.jpg"
             data-caption="Premium Tents"
             data-cat="rooms"
             onclick="openLb(this)">
            <img src="../assets/images/g8.jpg" alt="Tent Camping">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Premium Tents</div>
                <div class="gal-tile-cat">Rooms</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/g9.jpg"
             data-caption="Cottage Area"
             data-cat="cottages"
             onclick="openLb(this)">
            <img src="../assets/images/g9.jpg" alt="Cottage Area">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Cottage Area</div>
                <div class="gal-tile-cat">Cottages</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/g10.jpg"
             data-caption="Tropical Gardens"
             data-cat="views"
             onclick="openLb(this)">
            <img src="../assets/images/g10.jpg" alt="Tropical Gardens">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Tropical Gardens</div>
                <div class="gal-tile-cat">Views</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

    <!-- ── ROW 5 — Wide duo ── -->
    <div class="gal-row-5 gal-block">
        <div class="gal-tile"
             data-src="../assets/images/g11.jpg"
             data-caption="Resort Pool"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/g11.jpg" alt="Resort Pool">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Resort Pool</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/COTTAGES.jpg"
             data-caption="Gazebo Cottages"
             data-cat="cottages"
             onclick="openLb(this)">
            <img src="../assets/images/COTTAGES.jpg" alt="Gazebo Cottages">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Gazebo Cottages</div>
                <div class="gal-tile-cat">Cottages</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

    <!-- ── ROW 6 — Final three ── -->
    <div class="gal-row-4 gal-block" style="margin-top:14px;">
        <div class="gal-tile"
             data-src="../assets/images/13.jpg"
             data-caption="Lush Grounds"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/13.jpg" alt="Resort Grounds">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Lush Grounds</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/14.jpg"
             data-caption="Evening Ambiance"
             data-cat="views"
             onclick="openLb(this)">
            <img src="../assets/images/14.jpg" alt="Night View">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Evening Ambiance</div>
                <div class="gal-tile-cat">Views</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
        <div class="gal-tile"
             data-src="../assets/images/g12.jpg"
             data-caption="Stone Features"
             data-cat="pool"
             onclick="openLb(this)">
            <img src="../assets/images/g12.jpg" alt="Stone Features">
            <div class="gal-tile-overlay">
                <div class="gal-tile-label">Stone Features</div>
                <div class="gal-tile-cat">Pool &amp; Grounds</div>
            </div>
            <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
        </div>
    </div>

</div>

<!-- ══════════ FOOTER ══════════ -->
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

<!-- ══════════ JAVASCRIPT ══════════ -->
<script>
/* ── Filter ── */
function filterGal(cat, btn) {
    document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.gal-tile').forEach(tile => {
        const match = cat === 'all' || tile.dataset.cat === cat;
        tile.style.opacity        = match ? '1'         : '.18';
        tile.style.pointerEvents  = match ? ''          : 'none';
        tile.style.filter         = match ? ''          : 'grayscale(1)';
        tile.style.transition     = 'opacity .3s, filter .3s';
    });
}

/* ── Lightbox ── */
const allTiles = () => Array.from(document.querySelectorAll('.gal-tile'));
let curIdx = 0;

function openLb(el) {
    const all = allTiles();
    curIdx = all.indexOf(el);
    showLb(curIdx);
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function showLb(idx) {
    const t = allTiles()[idx];
    document.getElementById('lbImg').src             = t.dataset.src || t.querySelector('img').src;
    document.getElementById('lbCaption').textContent = t.dataset.caption || '';
    document.getElementById('lbCounter').textContent = (idx + 1) + ' / ' + allTiles().length;
}

function closeLb() {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('lbClose').onclick  = closeLb;
document.getElementById('lightbox').onclick = e => { if (e.target === document.getElementById('lightbox')) closeLb(); };

document.getElementById('lbPrev').onclick = () => {
    const all = allTiles();
    curIdx = (curIdx - 1 + all.length) % all.length;
    showLb(curIdx);
};

document.getElementById('lbNext').onclick = () => {
    curIdx = (curIdx + 1) % allTiles().length;
    showLb(curIdx);
};

document.addEventListener('keydown', e => {
    if (!document.getElementById('lightbox').classList.contains('open')) return;
    if (e.key === 'Escape')     closeLb();
    if (e.key === 'ArrowLeft')  { const all = allTiles(); curIdx = (curIdx - 1 + all.length) % all.length; showLb(curIdx); }
    if (e.key === 'ArrowRight') { curIdx = (curIdx + 1) % allTiles().length; showLb(curIdx); }
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