<?php
// pages/403.php

// URL'den 'hedef' parametresini al, yoksa 'Bilinmeyen Sayfa' yaz.
$hedef_sayfa = isset($_GET['hedef']) ? $_GET['hedef'] : 'Bu Sayfa';

// Güvenlik için temizle ve ilk harfini büyüt (örn: 'kullanici_listele' -> 'Kullanici_listele')
$route_name = htmlspecialchars(ucfirst($hedef_sayfa));
?>

<style>
    /* SAYFAYA ÖZEL MODERN STİLLER */
    .error-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 80vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        position: relative;
        overflow: hidden;
    }

    .bg-text {
        position: absolute;
        font-size: 20rem;
        font-weight: 900;
        color: rgba(0, 0, 0, 0.03);
        z-index: 0;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        pointer-events: none;
        user-select: none;
    }

    .error-card {
        background: #fff;
        padding: 40px 30px;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        text-align: center;
        max-width: 500px;
        width: 90%;
        position: relative;
        z-index: 1;
        border-top: 5px solid #dc3545;
    }

    .icon-box {
        width: 100px;
        height: 100px;
        background: #fff5f5;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px auto;
    }

    .icon-box i {
        font-size: 40px;
        color: #dc3545;
        animation: shake 2s infinite;
    }

    @keyframes shake {

        0%,
        100% {
            transform: rotate(0deg);
        }

        10%,
        30%,
        50%,
        70%,
        90% {
            transform: rotate(-5deg);
        }

        20%,
        40%,
        60%,
        80% {
            transform: rotate(5deg);
        }
    }

    .error-title {
        font-size: 28px;
        font-weight: 800;
        color: #343a40;
        margin-bottom: 10px;
    }

    .error-desc {
        color: #6c757d;
        font-size: 16px;
        line-height: 1.6;
        margin-bottom: 25px;
    }

    .highlight-route {
        background-color: #f8d7da;
        color: #721c24;
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: bold;
        font-family: monospace;
    }

    .btn-back {
        background: linear-gradient(45deg, #dc3545, #ff6b6b);
        border: none;
        padding: 12px 30px;
        border-radius: 50px;
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        text-decoration: none;
        display: inline-block;
    }

    .btn-back:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(220, 53, 69, 0.4);
        color: white;
        text-decoration: none;
    }

    @media (max-width: 576px) {
        .bg-text {
            font-size: 10rem;
        }

        .error-title {
            font-size: 24px;
        }

        .error-card {
            padding: 30px 20px;
        }
    }
</style>

<section class="content">
    <div class="container-fluid">
        <div class="error-wrapper">

            <div class="bg-text">403</div>

            <div class="error-card">
                <div class="icon-box">
                    <i class="fas fa-user-lock"></i>
                </div>

                <h1 class="error-title">Hop! Burası Yasak. 🚧</h1>

                <p class="error-desc">
                    Üzgünüz, gitmeye çalıştığın <span class="highlight-route"><?= $route_name ?></span> sayfasına giriş
                    yetkin yok. <br>

                </p>

                <p class="small text-muted mb-4">
                    Eğer bunun bir hata olduğunu düşünüyorsan, Sistem Yöneticinizle iletişime geçebilirsin.
                </p>

                <a href="anasayfa" class="btn-back">
                    <i class="fas fa-arrow-left mr-2"></i> Anasayfaya Geri Dön
                </a>
            </div>

        </div>
    </div>
</section>