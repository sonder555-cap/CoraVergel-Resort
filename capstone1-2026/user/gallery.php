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

<style>
/* =========================================================
   CORAVERGEL — GALLERY REDESIGN / OPTION 1
   Scoped to gallery.php so existing site functions remain intact.
   ========================================================= */
:root{
  --cv-gold:#bd9851;
  --cv-gold-dark:#9b7838;
  --cv-ink:#171613;
  --cv-muted:#716d63;
  --cv-cream:#f7f4ed;
  --cv-paper:#fffdf9;
  --cv-line:#e8e0d1;
}
body{background:var(--cv-paper)!important;color:var(--cv-ink);}

/* Hero / intro */
.page-header{
  position:relative!important;
  margin:0!important;
  min-height:360px!important;
  padding:92px 28px 86px!important;
  background:
    radial-gradient(circle at 88% 30%, rgba(189,152,81,.10) 0 92px, transparent 93px),
    linear-gradient(180deg,#fbf9f4 0%,#f6f1e8 100%)!important;
  border:0!important;
  overflow:hidden;
}
.page-header:before{
  content:"";position:absolute;left:-110px;bottom:-150px;width:320px;height:320px;
  border:1px solid rgba(189,152,81,.22);border-radius:50%;
}
.page-header:after{
  content:"";position:absolute;right:8%;bottom:42px;width:90px;height:1px;background:var(--cv-gold);opacity:.6;
}
.ph-inner{
  max-width:1160px!important;margin:auto!important;display:grid!important;
  grid-template-columns:1.15fr .85fr!important;gap:80px!important;align-items:end!important;
}
.ph-eyebrow,.gal-section-eyebrow,.gtp-eyebrow{
  color:var(--cv-gold-dark)!important;font-size:11px!important;font-weight:700!important;
  letter-spacing:.24em!important;text-transform:uppercase!important;
}
.ph-title{
  margin-top:14px!important;font-family:'Cormorant Garamond',Georgia,serif!important;
  font-size:clamp(58px,7vw,92px)!important;line-height:.83!important;letter-spacing:-.035em!important;
  color:var(--cv-ink)!important;font-weight:400!important;
}
.ph-title em{color:var(--cv-gold-dark)!important;font-weight:300!important;}
.ph-count{font-family:'DM Sans',sans-serif!important;color:var(--cv-ink)!important;font-size:12px!important;letter-spacing:.18em!important;text-transform:uppercase!important;margin-bottom:14px!important;}
.ph-sub{max-width:350px!important;margin:0!important;color:var(--cv-muted)!important;font-size:14px!important;line-height:1.8!important;}

/* Gallery shell */
.gallery-wrap{
  max-width:1080px!important;
  margin:0 auto!important;
  padding:58px 30px 100px!important;
}
.gal-quicknav{
  position:sticky!important;top:0;z-index:20!important;display:flex!important;justify-content:center!important;gap:8px!important;
  padding:14px 0!important;margin:0 auto 72px!important;background:rgba(255,253,249,.94)!important;
  backdrop-filter:blur(14px)!important;border-bottom:1px solid rgba(232,224,209,.85)!important;
  overflow-x:auto!important;scrollbar-width:none!important;
}
.gal-quicknav::-webkit-scrollbar{display:none;}
.gal-qn-item{
  flex:0 0 auto!important;display:inline-flex!important;align-items:center!important;gap:8px!important;
  padding:10px 17px!important;border:1px solid var(--cv-line)!important;border-radius:999px!important;
  color:#5f5a50!important;background:#fff!important;font-size:11px!important;font-weight:600!important;
  letter-spacing:.03em!important;text-decoration:none!important;transition:.25s ease!important;
}
.gal-qn-item i{color:var(--cv-gold)!important;font-size:11px!important;}
.gal-qn-item:hover,.gal-qn-item.is-active{background:var(--cv-gold)!important;color:#fff!important;border-color:var(--cv-gold)!important;transform:translateY(-1px);}
.gal-qn-item:hover i,.gal-qn-item.is-active i{color:#fff!important;}

/* Sections */
.gal-section{
  max-width:1040px!important;
  margin:0 auto 100px!important;
  padding:0!important;
  scroll-margin-top:90px!important;
}
.gal-section--last{margin-bottom:10px!important;}
.gal-section-head{
  display:flex!important;justify-content:space-between!important;align-items:flex-end!important;
  gap:30px!important;margin:0 0 26px!important;padding-bottom:17px!important;
  border-bottom:1px solid var(--cv-line)!important;
}
.gal-section-title{
  margin:8px 0 0!important;font-family:'Cormorant Garamond',Georgia,serif!important;
  font-size:clamp(34px,4vw,52px)!important;line-height:.95!important;font-weight:400!important;
  letter-spacing:-.025em!important;color:var(--cv-ink)!important;
}
.gal-section-count{color:#8a8377!important;font-size:10px!important;letter-spacing:.16em!important;text-transform:uppercase!important;white-space:nowrap!important;}

/* Editorial image grid */
.gal-masonry.gal-block{
  display:grid!important;
  grid-template-columns:repeat(12,minmax(0,1fr))!important;
  grid-auto-rows:110px!important;
  gap:10px!important;
  height:auto!important;
}
.gal-tile,.gal-text-panel,.gal-placeholder-tile{
  position:relative!important;overflow:hidden!important;border-radius:2px!important;border:0!important;
  min-width:0!important;box-shadow:none!important;
}
.gal-tile{cursor:zoom-in!important;background:#e9e4da!important;}
.gal-tile img{width:100%!important;height:100%!important;display:block!important;object-fit:cover!important;transition:transform .7s cubic-bezier(.2,.65,.2,1),filter .5s ease!important;}
.gal-tile:after{content:"";position:absolute;inset:0;opacity:.65;transition:.35s ease;pointer-events:none;}
.gal-tile:hover img{transform:scale(1.055)!important;filter:saturate(1.05)!important;}
.gal-tile:hover:after{opacity:.9;}
.gal-tile-expand{
  position:absolute!important;z-index:4!important;right:15px!important;top:15px!important;width:34px!important;height:34px!important;
  display:grid!important;place-items:center!important;border:none !important;border-radius:50%!important;
  background:rgba(20,18,14,.18)!important;color:#fff!important;font-size:11px!important;
  opacity:0!important;transform:translateY(-4px)!important;
  transition:opacity .3s ease, transform .3s ease!important;
}
.gal-tile:hover .gal-tile-expand{
  opacity:1!important;
  transform:translateY(0) rotate(8deg)!important;
}
/* Pool — hero composition */
#pool-playground .gal-tile:nth-child(1){grid-column:span 7!important;grid-row:span 4!important;}
#pool-playground .gal-tile:nth-child(2){grid-column:span 5!important;grid-row:span 2!important;}
#pool-playground .gal-tile:nth-child(3){grid-column:span 5!important;grid-row:span 2!important;}
#pool-playground .gal-tile:nth-child(4){grid-column:span 12!important;grid-row:span 2!important;}

/* Rooms */
#rooms-cottages .gal-text-panel{grid-column:span 4!important;grid-row:span 3!important;background:#1c1a16!important;color:#fff!important;padding:34px!important;display:flex!important;flex-direction:column!important;justify-content:flex-end!important;}
#rooms-cottages .gal-tile:nth-child(2){grid-column:span 4!important;grid-row:span 3!important;}
#rooms-cottages .gal-tile:nth-child(3){grid-column:span 4!important;grid-row:span 2!important;}
#rooms-cottages .gal-tile:nth-child(4){grid-column:span 4!important;grid-row:span 2!important;}
#rooms-cottages .gal-tile:nth-child(5){grid-column:span 4!important;grid-row:span 2!important;}
#rooms-cottages .gal-tile:nth-child(6){grid-column:span 4!important;grid-row:span 2!important;}
.gtp-title{font-family:'Cormorant Garamond',Georgia,serif!important;font-size:38px!important;line-height:.92!important;margin:12px 0 18px!important;color:#fff!important;}
.gtp-title em{color:#d4b46f!important;font-weight:300!important;}
.gtp-body{font-size:12px!important;line-height:1.7!important;color:rgba(255,255,255,.68)!important;margin:0 0 22px!important;}
.gtp-btn{display:inline-flex!important;align-items:center!important;gap:9px!important;align-self:flex-start!important;padding:11px 15px!important;border:1px solid #c9a45e!important;color:#e2c47f!important;text-decoration:none!important;font-size:10px!important;font-weight:700!important;letter-spacing:.08em!important;text-transform:uppercase!important;}
.gtp-btn:hover{background:#c9a45e!important;color:#171613!important;}

/* Other sections */
#coffee-billiard .gal-masonry.gal-block,#pavilions .gal-masonry.gal-block,#others .gal-masonry.gal-block{grid-auto-rows:150px!important;}
#coffee-billiard .gal-tile{grid-column:span 6!important;grid-row:span 2!important;}
#pavilions .gal-tile:nth-child(1){grid-column:span 7!important;grid-row:span 3!important;}
#pavilions .gal-tile:nth-child(2),#pavilions .gal-tile:nth-child(3){grid-column:span 5!important;grid-row:span 2!important;}
#pavilions .gal-tile:nth-child(4),#pavilions .gal-tile:nth-child(5){grid-column:span 5!important;grid-row:span 2!important;}
#others .gal-tile{grid-column:span 3!important;grid-row:span 3!important;}

/* Lightbox upgrade */
.lightbox{background:rgba(12,11,9,.94)!important;backdrop-filter:blur(10px)!important;}
.lb-img-wrap{max-width:min(1180px,88vw)!important;max-height:78vh!important;}
.lb-img-wrap img{max-width:100%!important;max-height:78vh!important;object-fit:contain!important;}
.lb-close,.lb-nav-btn{border:transparent;background:transparent;color:#fff!important;transition:.25s ease!important;}
.lb-caption{font-family:'Cormorant Garamond',Georgia,serif!important;font-size:25px!important;color:#fff!important;}
.lb-counter{color:rgba(255,255,255,.55)!important;letter-spacing:.15em!important;}

/* Footer breathing room */
.site-footer{margin-top:0!important;}

@media(max-width:900px){
  .page-header{min-height:300px!important;padding:72px 24px 64px!important;}
  .ph-inner{grid-template-columns:1fr!important;gap:28px!important;}
  .ph-title{font-size:64px!important;}
  .gallery-wrap{padding:35px 18px 70px!important;}
  .gal-quicknav{justify-content:flex-start!important;margin-bottom:52px!important;}
  .gal-masonry.gal-block{grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-auto-rows:190px!important;}
  .gal-tile,.gal-text-panel,.gal-placeholder-tile{grid-column:span 1!important;grid-row:span 1!important;}
  #pool-playground .gal-tile:nth-child(1){grid-column:span 2!important;grid-row:span 2!important;}
  #rooms-cottages .gal-text-panel{grid-column:span 2!important;grid-row:span 2!important;}
  #pavilions .gal-tile:nth-child(1){grid-column:span 2!important;grid-row:span 2!important;}
  .gal-section{margin-bottom:75px!important;}
}
@media(max-width:560px){
  .page-header{padding:58px 18px 48px!important;}
  .ph-title{font-size:52px!important;}
  .ph-sub{font-size:13px!important;}
  .gal-section-head{align-items:flex-start!important;flex-direction:column!important;gap:9px!important;}
  .gal-section-title{font-size:39px!important;}
  .gal-masonry.gal-block{gap:8px!important;grid-auto-rows:155px!important;}
  #rooms-cottages .gal-text-panel{padding:24px!important;}
  .gtp-title{font-size:31px!important;}
}
</style>

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

    <a href="index.php" class="navbar-brand">
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
            <p class="ph-sub">Explore the beauty, comfort, and character of CoraVergel Resort — one unforgettable moment at a time.</p>
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
        <a href="#pool-playground" class="gal-qn-item is-active"><i class="fa-solid fa-water-ladder"></i> Pool &amp; Playground</a>
        <a href="#coffee-billiard" class="gal-qn-item"><i class="fa-solid fa-mug-hot"></i> Coffee &amp; Billiard</a>
        <a href="#rooms-cottages" class="gal-qn-item"><i class="fa-solid fa-house-chimney"></i> Rooms &amp; Cottages</a>
        <a href="#pavilions" class="gal-qn-item"><i class="fa-solid fa-people-roof"></i> Pavilions &amp; Spaces</a>
        <a href="#others" class="gal-qn-item"><i class="fa-solid fa-images"></i> Others</a>
    </nav>

    <!-- ══════════════════════════════════════════════
         SECTION — POOL & PLAYGROUND
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="pool-playground">
        <div class="gal-section-head">
            <div>
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
                 data-src="../assets/images/g10.jpg"
                 data-caption="Resort Landscape"
                 onclick="openLb(this)">
                <img src="../assets/images/g10.jpg" alt="Resort Landscape">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/background.jpg"
                 data-caption="Tropical Gardens"
                 onclick="openLb(this)">
                <img src="../assets/images/background.jpg" alt="Tropical Gardens">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════
         SECTION — COFFEE & BILLIARD
    ══════════════════════════════════════════════ -->
    <section class="gal-section" id="coffee-billiard">
        <div class="gal-section-head">
            <div>
                <h2 class="gal-section-title">Coffee &amp; Billiard</h2>
            </div>
            <div class="gal-section-count"></div>
        </div>

        <div class="gal-masonry gal-block">
            <div class="gal-tile"
                 data-src="../assets/images/2.jpg"
                 data-caption="Coffee Corner"
                 onclick="openLb(this)">
                <img src="../assets/images/2.jpg" alt="Coffee Corner">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/1.jpg"
                 data-caption="Billiard Room"
                 onclick="openLb(this)">
                <img src="../assets/images/1.jpg" alt="Billiard Room">
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
                 data-src="../assets/images/large-bahay-kubo.jpg"
                 data-caption="Bahay Kubo Interior"
                 onclick="openLb(this)">
                <img src="../assets/images/large-bahay-kubo.jpg" alt="Bahay Kubo Interior">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/duplex-room.jpg"
                 data-caption="Duplex Room Interior"
                 onclick="openLb(this)">
                <img src="../assets/images/duplex-room.jpg" alt="Duplex Room Interior">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/family-room.jpg"
                 data-caption="Family Room, Bunk Beds"
                 onclick="openLb(this)">
                <img src="../assets/images/family-room.jpg" alt="Family Room, Bunk Beds">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/small-bahay-kubo.jpg"
                 data-caption="Bahay Kubo Bedding"
                 onclick="openLb(this)">
                <img src="../assets/images/small-bahay-kubo.jpg" alt="Bahay Kubo Bedding">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/g7.jpg"
                 data-caption="Garden View"
                 onclick="openLb(this)">
                <img src="../assets/images/g7.jpg" alt="Garden View">
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
                <h2 class="gal-section-title">Pavilions &amp; Gathering Spaces</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
            <div class="gal-tile"
                 data-src="../assets/images/pavilion-kubo.jpg"
                 data-caption="Kubo Bulawan Pavilion"
                 onclick="openLb(this)">
                <img src="../assets/images/pavilion-kubo.jpg" alt="Kubo Bulawan Pavilion">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/kubo-area-hall.png"
                 data-caption="Kubo Area Dining Hall"
                 onclick="openLb(this)">
                <img src="../assets/images/kubo-area-hall.png" alt="Kubo Area Dining Hall">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/family-hall-event.jpeg"
                 data-caption="Family Hall Function Room"
                 onclick="openLb(this)">
                <img src="../assets/images/family-hall-event.jpeg" alt="Family Hall Function Room">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/poolside-pavilion.jpeg"
                 data-caption="Poolside Pavilion"
                 onclick="openLb(this)">
                <img src="../assets/images/poolside-pavilion.jpeg" alt="Poolside Pavilion">
                <div class="gal-tile-expand"><i class="fa-solid fa-expand"></i></div>
            </div>
            <div class="gal-tile"
                 data-src="../assets/images/Small Bahay Kubo.jpg"
                 data-caption="Small Bahay Kubo"
                 onclick="openLb(this)">
                <img src="../assets/images/Small Bahay Kubo.jpg" alt="Small Bahay Kubo">
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
                <h2 class="gal-section-title">Others</h2>
            </div>
        </div>

        <div class="gal-masonry gal-block">
            <div class="gal-tile"
                 data-src="../assets/images/14.jpg"
                 data-caption="Evening Ambiance"
                 onclick="openLb(this)">
                <img src="../assets/images/14.jpg" alt="Evening Ambiance">
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
            <div class="gal-tile"
                 data-src="../assets/images/12.jpg"
                 data-caption="Poolside Retreat"
                 onclick="openLb(this)">
                <img src="../assets/images/12.jpg" alt="Poolside Retreat">
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
                <a href="https://www.tiktok.com/@coravergel.resort" aria-label="TikTok" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-links">
        <div class="footer-col">
            <h4>About CoraVergel Resort</h4>
            <a href="about.php">About </a>
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
            <a href="resort_policies.php">Resort Policy</a>
            <a href="#">Terms of Use</a>
        </div>
    </div>
</footer>

<!-- ══════════ JAVASCRIPT ══════════ -->
<script>
/* ── Gallery section navigation ── */
document.querySelectorAll('.gal-qn-item').forEach(link => {
  link.addEventListener('click', () => {
    document.querySelectorAll('.gal-qn-item').forEach(x => x.classList.remove('is-active'));
    link.classList.add('is-active');
  });
});

const gallerySections = Array.from(document.querySelectorAll('.gal-section'));
const galleryLinks = Array.from(document.querySelectorAll('.gal-qn-item'));
const galleryObserver = new IntersectionObserver(entries => {
  const visible = entries.filter(e => e.isIntersecting).sort((a,b) => b.intersectionRatio-a.intersectionRatio)[0];
  if (!visible) return;
  const id = visible.target.id;
  galleryLinks.forEach(l => l.classList.toggle('is-active', l.getAttribute('href') === '#' + id));
}, {rootMargin:'-25% 0px -55% 0px', threshold:[0,.2,.5]});
gallerySections.forEach(s => galleryObserver.observe(s));

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