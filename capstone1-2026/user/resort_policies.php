<?php
$page_title = 'Resort Policies | CoraVergel Resort';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?></title>
<link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
<link rel="stylesheet" href="../assets/css/user.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
/* ── Resort Policies — Privacy-Policy-style document layout ── */
.pp-page{
    /* override the global `main{display:flex;justify-content:center;...}` rule
       so the hero / title / body sections stack full-width instead of being
       squeezed into a centered row */
    display:block !important;
    min-height:auto !important;
    padding:0 !important;
    width:100%;
    background:#fff;
    color:#2f2a22;
    font-family:'DM Sans', Arial, sans-serif;
}

.resort-h {
position: relative;
height: 420px;
overflow: hidden;
}
.resort-h-img {
width: 100%;
height: 100%;
object-fit: cover;
display: block;
}
.pp-page .resort-hero{
    width:100%;
    flex:none;
}
.pp-title-wrap{
    width:100%;
    text-align:center;
    padding:64px 24px 44px;
}
.pp-title{
    font-family:'Cormorant Garamond', serif;
    font-weight:600;
    font-size:clamp(2.2rem, 4vw, 3.2rem);
    color:#2f2a22;
    margin:0;
}
.pp-body{
    width:100%;
    max-width:760px;
    margin:0 auto;
    padding:0 24px 100px;
    box-sizing:border-box;
}
.pp-section{
    margin-bottom:38px;
}
.pp-section h2{
    font-family:'DM Sans', sans-serif;
    font-size:1rem;
    font-weight:700;
    color:#2f2a22;
    margin:0 0 12px;
}
.pp-section p{
    color:#433d33;
    line-height:1.7;
    font-size:.85rem;
    margin:0 0 14px;
}
.pp-section ul{
    margin:0 0 14px;
    padding-left:22px;
    color:#433d33;
    line-height:1.7;
    font-size:.85rem;
}
.pp-section li{ margin-bottom:6px; }
.pp-section a{ color:var(--gold-dark); text-decoration:underline; }
.pp-section ol{
    margin:0 0 14px;
    padding-left:22px;
    color:#433d33;
    line-height:1.7;
    font-size:.85rem;
}
.pp-section ol li{ margin-bottom:12px; }
.pp-dot-list{
    list-style:none;
    margin:0 0 14px;
    padding:0;
    color:#433d33;
    line-height:1.7;
    font-size:.85rem;
}
.pp-dot-list li{
    position:relative;
    padding-left:18px;
    margin-bottom:10px;
}
.pp-dot-list li::before{
    content:'';
    position:absolute;
    left:2px;
    top:.55em;
    width:6px;
    height:6px;
    border-radius:50%;
    background:var(--gold-dark, #8b6914);
}
.pp-dot-list li strong{ color:#2f2a22; }
.pp-sub{
    font-family:'DM Sans', sans-serif;
    font-size:.78rem;
    font-weight:700;
    letter-spacing:.03em;
    text-transform:uppercase;
    color:var(--gold-dark, #8b6914);
    margin:20px 0 8px;
}
.pp-sub:first-child{ margin-top:0; }
.pp-price-list{
    list-style:none;
    margin:0 0 10px;
    padding:0;
}
.pp-price-list li{
    display:flex;
    justify-content:space-between;
    gap:16px;
    padding:6px 0;
    border-bottom:1px dashed #e6ddc9;
    color:#433d33;
    font-size:.85rem;
}
.pp-price-list li:last-child{ border-bottom:none; }
.pp-note{
    margin-top:10px;
    padding:12px 16px;
    background:#faf7f0;
    border-left:3px solid var(--gold-dark, #8b6914);
    border-radius:4px;
    font-size:.8rem;
    line-height:1.65;
    color:#5c5133;
}
.pp-note.pp-note-alert{
    background:#fbeeee;
    border-left-color:#c0392b;
    color:#7a2b23;
}
@media (max-width:760px){
    .pp-title-wrap{ padding:44px 20px 30px; }
    .pp-body{ padding:0 20px 70px; }
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
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="nav-drawer" id="navDrawer">
    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
        <a href="gallery.php">Gallery <i class="fa-solid fa-chevron-right"></i></a>
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

<main class="pp-page">

<section class="resort-h">
    <img class="resort-h-img" src="../assets/images/11.jpg" alt="CoraVergel Resort">
</section>

<div class="pp-title-wrap">
    <h1 class="pp-title">Resort Policies</h1>
</div>

<div class="pp-body">

    <div class="pp-section">
        <h2>Pet Policy</h2>
        <p>Pets are <strong>not allowed</strong> at CoraVergel Resort. We understand how important your pet is, but unfortunately we can't accommodate pets at this time.</p>
        <p>Violators will be asked to leave and will receive no refund. We hope you'll consider this before selecting a resort that allows pets as part of their policy.</p>
    </div>

    <div class="pp-section">
        <h2>Guidelines Before Booking</h2>
        <ul class="pp-dot-list">
            <li><strong>Accommodation:</strong> Guests must rent one of our accommodations.</li>
            <li><strong>Occupancy &amp; Room Service:</strong> Room capacity shall be strictly observed. An additional amount shall be charged in excess of maximum occupancy.</li>
            <li><strong>Cottage &amp; Venues:</strong> Please occupy only the assigned cottage you have paid for — capacity is strictly followed.</li>
            <li><strong>Fees:</strong> Entrance and swimming fees are separate from the accommodation fee.</li>
            <li><strong>Swimming Attire:</strong> Proper swimming attire is strictly required.</li>
            <li><strong>Liability:</strong> Rates do not cover insurance of any sort, so guests are advised to take precautions. Management is not liable for any accident or injury.</li>
            <li><strong>Outside Food:</strong> We have no restaurant inside the resort, so bringing food is allowed without a corkage fee. Corkage fee applies to drinks only.</li>
            <li><strong>Reservation Hours:</strong> 8:00 AM to 9:00 PM daily.</li>
            <li><strong>Quiet Hours:</strong> 10:00 PM to 7:00 AM.</li>
            <li><strong>Speakers:</strong> Bluetooth speakers are allowed, not exceeding 12 inches in size.</li>
        </ul>
        <p><em>*Rates might change on holidays without prior notice.</em></p>
    </div>

    <div class="pp-section">
        <h2>Corkage &amp; Fees</h2>
        <p>Outside food may be brought in free of charge. A corkage fee applies to outside drinks and to appliance use, as follows:</p>

        <div class="pp-sub">Softdrinks / Sparkling / Non-Alcoholic</div>
        <ul class="pp-price-list">
            <li><span>Per case</span><span>&#8369;150</span></li>
            <li><span>Per bottle</span><span>&#8369;50</span></li>
        </ul>

        <div class="pp-sub">Alcoholic Drinks</div>
        <ul class="pp-price-list">
            <li><span>Per case</span><span>&#8369;400</span></li>
            <li><span>Per bottle (750ml above)</span><span>&#8369;150</span></li>
        </ul>

        <div class="pp-sub">Appliances</div>
        <p>Heater, rice cooker, water dispenser, etc. — &#8369;100 to &#8369;500, depending on the appliance.</p>
    </div>

    <div class="pp-section">
        <h2>Check-In &amp; Check-Out Schedule</h2>

        <div class="pp-sub">Day Stay</div>
        <ul class="pp-price-list">
            <li><span>Check-in</span><span>8:00 AM</span></li>
            <li><span>Check-out</span><span>4:00 PM</span></li>
        </ul>

        <div class="pp-sub">Night Stay <span style="text-transform:none;font-weight:500;">(with reservations only)</span></div>
        <ul class="pp-price-list">
            <li><span>Check-in</span><span>5:00 PM</span></li>
            <li><span>Check-out</span><span>12:00 MN</span></li>
        </ul>

        <div class="pp-sub">Overnight</div>
        <ul class="pp-price-list">
            <li><span>Check-in</span><span>2:00 PM</span></li>
            <li><span>Check-out</span><span>12:00 NN next day</span></li>
        </ul>

        <p>Early check-in and late check-out are charged per hour: &#8369;150/hour for fan rooms and &#8369;250/hour for AC rooms, subject to room/cottage availability. Our customer service team is not available after 8:00 PM.</p>
        <p>Weekdays include 1 pool access; weekends and holidays include 2 pool accesses.</p>

        <div class="pp-note pp-note-alert">
            Please be advised that the Resort reserves the right to cancel reservations for guests who do not comply with our policies and guidelines. Thank you!
        </div>
    </div>

    <div class="pp-section">
        <h2>General Reminders</h2>
        <ul>
            <li>Gambling is strictly prohibited on the premises.</li>
            <li>We do not provide utensils or cooking ware — please bring your own.</li>
            <li>Speakers are allowed, but please be considerate of neighboring cottages.</li>
            <li>For large events or parties, we recommend renting our Kubo, Pavilion, or an entire Large Gazebo for better privacy.</li>
        </ul>
    </div>

</div>
</main>

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
            <a href="index.php#contact">Contact Us</a>
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