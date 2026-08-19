<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/user.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

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
            <a href="gallery.php" class="active-link">GALLERY</a>
            <a href="deals.php">DEALS</a>
            <a href="index.php#contact">CONTACT</a>
        </div>
    </div>

    <a href="dashboard.php" class="navbar-brand">
        <div class="custom-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- Slide Drawer -->
<div class="nav-drawer" id="navDrawer">

    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
        <a href="gallery.php" class="active-link">Gallery <i class="fa-solid fa-chevron-right"></i></a>
        <a href="deals.php">Deals <i class="fa-solid fa-chevron-right"></i></a>
        <a href="index.php#contact">Contact <i class="fa-solid fa-chevron-right"></i></a>
    </nav>

    <div class="drawer-footer">
        <div class="drawer-footer-eyebrow">Resort Tigbauan, Iloilo</div>
        <div class="drawer-footer-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel">
            <span class="drawer-footer-name">CoraVergel Resort</span>
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
            <p class="ph-sub">Nestled along the shores of Tigbauan, Iloilo — browse the resort by the corner of it that calls to you.</p>
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

    <!-- ── QUICK NAV — jumps to each section below ── -->
    <nav class="gal-quicknav" aria-label="Gallery sections">
        <a href="#pool-playground" class="gal-qn-item"><i class="fa-solid fa-water-ladder"></i> Pool &amp; Playground</a>
        <a href="#coffee-billiard" class="gal-qn-item"><i class="fa-solid fa-mug-hot"></i> Coffee &amp; Billiard</a>
        <a href="#rooms-cottages" class="gal-qn-item"><i class="fa-solid fa-house-chimney"></i> Rooms &amp; Cottages</a>
        <a href="#pavilions" class="gal-qn-item"><i class="fa-solid fa-people-roof"></i> Pavilions &amp; Gathering Spaces</a>
        <a href="#others" class="gal-qn-item"><i class="fa-solid fa-images"></i> Others</a>
    </nav>

    <!-- ══════════════════════════════════════════════
         SECTION — POOL & PLAYGROUND
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="pool-playground">
        <div class="gal-section-head">
            <div>
                <div class="gal-section-eyebrow">Cool Off &amp; Play</div>
                <h2 class="gal-section-title">Pool &amp; Playground</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
            <div class="gal-tile"
                 data-src="../assets/images/11.jpg"
                 data-caption="Poolside Grounds"
                 onclick="openLb(this)">
                <img src="../assets/images/11.jpg" alt="Poolside Grounds">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/g11.jpg"
                 data-caption="Resort Pool"
                 onclick="openLb(this)">
                <img src="../assets/images/g11.jpg" alt="Resort Pool">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/12.jpg"
                 data-caption="Poolside Retreat"
                 onclick="openLb(this)">
                <img src="../assets/images/12.jpg" alt="Poolside Retreat">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/g12.jpg"
                 data-caption="Stone Features"
                 onclick="openLb(this)">
                <img src="../assets/images/g12.jpg" alt="Stone Features">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/13.jpg"
                 data-caption="Lush Grounds"
                 onclick="openLb(this)">
                <img src="../assets/images/13.jpg" alt="Lush Grounds">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         SECTION — COFFEE & BILLIARD
         NOTE: no source photos for this category were
         included in the current asset set — swap the
         two placeholder tiles below for real photos
         (e.g. ../assets/images/coffee1.jpg,
         ../assets/images/billiard1.jpg) once available.
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="coffee-billiard">
        <div class="gal-section-head">
            <div>
                <div class="gal-section-eyebrow">Unwind Indoors</div>
                <h2 class="gal-section-title">Coffee &amp; Billiard</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
  <div class="gal-tile"
                 data-src="../assets/images/2.jpg"
                 data-caption="Coffee"
                 onclick="openLb(this)">
                <img src="../assets/images/2.jpg" alt="Swimming Pool">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         SECTION — ROOMS & COTTAGES
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="rooms-cottages">
        <div class="gal-section-head">
            <div>
                <div class="gal-section-eyebrow">Where You Rest</div>
                <h2 class="gal-section-title">Rooms &amp; Cottages</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
            <div class="gal-text-panel">
                <div class="gtp-eyebrow">Stay With Us</div>
                <div class="gtp-title">Comfort for every<br><em>kind</em> of guest</div>
                <p class="gtp-body">From cozy bahay kubo cottages to spacious duplex rooms, every stay is built around rest, shade, and the sound of the outdoors.</p>
                <a href="./rooms.php" class="gtp-btn">
                    <i class="fa-solid fa-bed"></i> View Rooms &amp; Rates
                </a>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/rooms/room_6a7c806f93c5e.jpg"
                 data-caption="Bahay Kubo Interior"
                 onclick="openLb(this)">
                <img src="../assets/images/rooms/room_6a7c806f93c5e.jpg" alt="Bahay Kubo Interior">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/rooms/room_6a7c7fe1878ba.jpg"
                 data-caption="Duplex Room Interior"
                 onclick="openLb(this)">
                <img src="../assets/images/rooms/room_6a7c7fe1878ba.jpg" alt="Duplex Room Interior">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/rooms/room_6a7c801330881.jpg"
                 data-caption="Family Room, Bunk Beds"
                 onclick="openLb(this)">
                <img src="../assets/images/rooms/room_6a7c801330881.jpg" alt="Family Room, Bunk Beds">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/rooms/room_6a7efd8eb5fc5.jpg"
                 data-caption="Bahay Kubo Bedding"
                 onclick="openLb(this)">
                <img src="../assets/images/rooms/room_6a7efd8eb5fc5.jpg" alt="Bahay Kubo Bedding">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         SECTION — PAVILIONS & GATHERING SPACES
         Swap the filenames below for wherever you save
         these five uploads on the server.
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="pavilions">
        <div class="gal-section-head">
            <div>
                <div class="gal-section-eyebrow">Gather &amp; Celebrate</div>
                <h2 class="gal-section-title">Pavilions &amp; Gathering Spaces</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
              <div class="gal-tile"
                 data-src="../assets/images/gallery/family-hall-event.jpeg"
                 data-caption="Family Hall Function Room"
                 onclick="openLb(this)">
                <img src="../assets/images/gallery/family-hall-event.jpeg" alt="Family Hall Function Room">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/gallery/pavilion-kubo.jpg"
                 data-caption="Kubo Bulawan Pavilion"
                 onclick="openLb(this)">
                <img src="../assets/images/gallery/pavilion-kubo.jpg" alt="Kubo Bulawan Pavilion">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/gallery/kubo-area-hall.png"
                 data-caption="Kubo Area Dining Hall"
                 onclick="openLb(this)">
                <img src="../assets/images/gallery/kubo-area-hall.png" alt="Kubo Area Dining Hall">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
          
            <div class="gal-tile"
                 data-src="../assets/images/gallery/poolside-pavilion.jpeg"
                 data-caption="Poolside Pavilion"
                 onclick="openLb(this)">
                <img src="../assets/images/gallery/poolside-pavilion.jpeg" alt="Poolside Pavilion">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/gallery/Small Bahay Kubo.jpg"
                 data-caption="Small Bahay Kubo"
                 onclick="openLb(this)">
                <img src="../assets/images/gallery/Small Bahay Kubo.jpg" alt="Small Bahay Kubo">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         SECTION — OTHERS (general / miscellaneous shots)
    ══════════════════════════════════════════════ -->
    <section class="gal-section gal-section--last" id="others">
        <div class="gal-section-head">
            <div>
                <div class="gal-section-eyebrow">A Little Bit of Everything</div>
                <h2 class="gal-section-title">Others</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
                        <div class="gal-tile"
                 data-src="../assets/images/where-family-fun-begins.png"
                 data-caption="Evening Ambiance"
                 onclick="openLb(this)">
                <img src="../assets/images/where-family-fun-begins.png" alt="Evening Ambiance">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>

            <div class="gal-tile"
                 data-src="../assets/images/1.jpg"
                 data-caption="Resort Aerial View"
                 onclick="openLb(this)">
                <img src="../assets/images/1.jpg" alt="Resort Aerial View">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/background.jpg"
                 data-caption="Resort Landscape"
                 onclick="openLb(this)">
                <img src="../assets/images/background.jpg" alt="Resort Landscape">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/g7.jpg"
                 data-caption="Garden View"
                 onclick="openLb(this)">
                <img src="../assets/images/g7.jpg" alt="Garden View">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/g10.jpg"
                 data-caption="Tropical Gardens"
                 onclick="openLb(this)">
                <img src="../assets/images/g10.jpg" alt="Tropical Gardens">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/14.jpg"
                 data-caption="Evening Ambiance"
                 onclick="openLb(this)">
                <img src="../assets/images/14.jpg" alt="Evening Ambiance">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

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

<!-- ══════════ JAVASCRIPT ══════════ -->
<script>
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

/* ── Mobile drawer ── */
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