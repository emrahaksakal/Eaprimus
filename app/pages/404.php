<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Sayfa Bulunamadı</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">

    <style>
        /* SAYFAYA ÖZEL MODERN STİLLER */
        body {
            background-color: #f4f6f9;
            margin: 0;
        }
        .error-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh; /* Ekranı tam kapla */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Arka plandaki devasa silik 404 yazısı */
        .bg-text {
            position: absolute;
            font-size: 20rem;
            font-weight: 900;
            color: rgba(255, 193, 7, 0.05); /* Hafif Sarı */
            z-index: 0;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            user-select: none;
        }

        /* Kart Tasarımı */
        .error-card {
            background: #fff;
            padding: 40px 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            text-align: center;
            max-width: 500px;
            width: 90%;
            position: relative;
            z-index: 1;
            border-top: 5px solid #ffc107; /* Sarı çizgi */
        }

        /* İkon Kutusu ve Animasyonu */
        .icon-box {
            width: 100px;
            height: 100px;
            background: #fff3cd; /* Açık sarı */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
        }
        .icon-box i {
            font-size: 40px;
            color: #ffc107;
            animation: pulse 2s infinite; /* Nefes alma efekti */
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Yazı Stilleri */
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

        /* Buton Stili */
        .btn-back {
            background: linear-gradient(45deg, #ffc107, #ffca2c);
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            color: #343a40;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
            text-decoration: none;
            display: inline-block;
        }
        .btn-back:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.4);
            color: #212529;
            text-decoration: none;
        }

        /* Mobil Ayarlar */
        @media (max-width: 576px) {
            .bg-text { font-size: 10rem; }
            .error-title { font-size: 24px; }
            .error-card { padding: 30px 20px; }
        }
    </style>
</head>
<body>

    <div class="error-wrapper">

        <div class="bg-text">404</div>

        <div class="error-card">
            <div class="icon-box">
                <i class="fas fa-search"></i>
            </div>

            <h1 class="error-title">Oops! Kaybolduk Galiba.</h1>

            <p class="error-desc">
                Aradığın sayfayı yerin dibine girse de bulamadık <br>
                Belki link yanlıştır ya da sayfa tatile çıkmıştır. 🏖️
            </p>

            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-home mr-2"></i> Yuvaya Dön
            </a>
        </div>

    </div>

</body>
</html>
