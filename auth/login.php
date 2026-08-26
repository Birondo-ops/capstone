<?php
session_start();
require 'conn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $forgot_user = trim($_POST['forgot_username'] ?? '');
    $forgot_email = trim($_POST['forgot_email'] ?? '');

    if (empty($forgot_user) || empty($forgot_email)) {
        echo "<script>alert('All fields are required for password reset.'); history.back();</script>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name FROM admin WHERE username = ? AND email = ? LIMIT 1");
        $stmt->execute([$forgot_user, $forgot_email]);
        $admin = $stmt->fetch();

        if ($admin) {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            $update = $pdo->prepare("UPDATE admin SET reset_token = ?, reset_expires_at = ? WHERE id = ?");
            $update->execute([$token, $expiry, $admin['id']]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            
            $resetLink = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;

            if (sendRecoveryEmail($forgot_email, $admin['name'], $resetLink)) {
                echo "<script>alert('Account verified! A secure reset link has been sent to your email.'); window.location.href = '';</script>";
            } else {
                echo "<script>alert('Account verified, but email delivery failed. Please check your SMTP settings.'); history.back();</script>";
            }
            exit();
        } else {
            echo "<script>alert('Verification failed. Invalid Username or Recovery Email combination.'); history.back();</script>";
            exit();
        }
    } catch (PDOException $e) {
        error_log("Forgot Password Error: " . $e->getMessage());
        echo "<script>alert('System processing error. Please try again.'); history.back();</script>";
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['action'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username)) {
        echo "<script>alert('Please enter your username.'); history.back();</script>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name, password FROM admin WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {

            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['logged_in'] = true;

            echo "<script>
                    alert('Welcome back, " . addslashes($admin['name']) . "!');
                    window.location.href ='../index.php';
                  </script>";
            exit();

        } else {
            echo "<script>alert('Invalid credentials. Access Denied.'); history.back();</script>";
        }

    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        echo "<script>alert('System error. Please try again later.');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - SalesCore Supplies</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary-orange: #e86d25;
        --dark-orange: #d15a14;
        --bg-gray: #f2f2f0;
        --card-bg: #fcfcf9;
        --text-dark: #333333;
        --text-muted: #888888;
        --input-border: #e0e0e0;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        margin: 0;
        padding: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        background-color: var(--bg-gray);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        position: relative;
        overflow-x: hidden;
    }

    /* Card Wrapper matching Image layout */
    .login-card {
        background: var(--card-bg);
        width: 100%;
        max-width: 410px;
        padding: 40px 35px 35px 35px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        border: 1px solid #ebebeb;
        z-index: 1;
        text-align: center;
    }

    .brand-section {
        margin-bottom: 25px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* Pure SVG Embedded Logo Styling */
    .svg-logo-wrapper {
        width: 150px;
        height: 150px;
        margin-bottom: 12px;
    }

    .brand-subtitle {
        font-size: 14px;
        color: #999999;
        font-weight: 500;
        letter-spacing: 0.2px;
    }

    .input-group {
        position: relative;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        background: #ffffff;
        border-radius: 10px; 
        border: 1px solid var(--input-border);
        padding: 6px 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .input-group:focus-within {
        border-color: var(--primary-orange);
        box-shadow: 0 0 0 3px rgba(232, 109, 37, 0.1);
    }

    .input-icon {
        color: #777777;
        font-size: 15px;
        margin-right: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .input-group input {
        width: 100%;
        border: none;
        background: transparent;
        padding: 10px 0;
        font-size: 15px;
        color: var(--text-dark);
        outline: none;
    }

    .input-group input::placeholder {
        color: #cccccc;
        font-size: 15px;
    }

    .password-toggle {
        color: #aaaaaa;
        cursor: pointer;
        padding-left: 8px;
        font-size: 15px;
    }

    .password-toggle:hover {
        color: var(--primary-orange);
    }

    .forgot-link-wrapper {
        text-align: right;
        margin: -6px 4px 22px 0;
    }

    .forgot-password-link {
        color: #777777;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: color 0.2s;
    }

    .forgot-password-link:hover {
        color: var(--primary-orange);
        text-decoration: underline;
    }

    .btn-login {
        background-color: var(--primary-orange);
        color: #ffffff;
        border: none;
        padding: 14px;
        width: 100%;
        border-radius: 10px;
        font-size: 17px;
        font-weight: 600;
        letter-spacing: 0.3px;
        cursor: pointer;
        box-shadow: 0 3px 8px rgba(232, 109, 37, 0.25);
        transition: background-color 0.2s, transform 0.1s;
    }

    .btn-login:hover {
        background-color: var(--dark-orange);
    }

    .btn-login:active {
        transform: scale(0.99);
    }

    /* Modal Styling */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.4);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 999;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
    }

    .modal-overlay.active {
        opacity: 1;
        pointer-events: auto;
    }

    .modal-box {
        background: #ffffff;
        width: 90%;
        max-width: 400px;
        padding: 30px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        transform: translateY(-20px);
        transition: transform 0.3s ease;
    }

    .modal-overlay.active .modal-box {
        transform: translateY(0);
    }

    .modal-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 10px;
    }

    .modal-subtitle {
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 20px;
        line-height: 1.4;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 25px;
    }

    .btn-cancel {
        background: #e0e0e0;
        color: var(--text-dark);
        border: none;
        padding: 12px;
        width: 50%;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-submit-forgot {
        background: var(--primary-orange);
        color: #ffffff;
        border: none;
        padding: 12px;
        width: 50%;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-submit-forgot:hover {
        background: var(--dark-orange);
    }

    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(4px);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
    }

    .loading-overlay.visible {
        opacity: 1;
        pointer-events: auto;
    }

    .spinner-box {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
    }

    .loading-spinner {
        font-size: 50px;
        color: var(--primary-orange);
        animation: spin 1.5s linear infinite;
    }

    .loading-text {
        color: #ffffff;
        font-size: 16px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-box">
        <i class="fa-solid fa-gear loading-spinner"></i>
        <div class="loading-text">Sending recovery email, please wait...</div>
    </div>
</div>

<div class="login-card">
    <div class="brand-section">
        <!-- Built-in Vector Logo (No Image Path Needed) -->
        <div class="svg-logo-wrapper">
            <svg viewBox="0 0 300 300" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                <!-- Outer Ring -->
                <circle cx="150" cy="150" r="140" fill="#fcfcf9" stroke="#e86d25" stroke-width="18"/>
                <!-- Inner Orange Circle -->
                <circle cx="150" cy="165" r="70" fill="#e86d25"/>
                <!-- Dots -->
                <circle cx="45" cy="150" r="6" fill="#e86d25"/>
                <circle cx="255" cy="150" r="6" fill="#e86d25"/>
                
                <!-- Cleaning Icons Inside Circle -->
                <g fill="none" stroke="#fcfcf9" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
                    <!-- Spray Bottle -->
                    <path d="M100,160 L110,160 L112,145 L102,145 Z" fill="#fcfcf9"/>
                    <path d="M107,145 L107,138 L98,138" stroke-width="3"/>
                    <path d="M98,160 C90,170 90,195 100,200 C110,205 118,195 112,160 Z" fill="#fcfcf9"/>
                    
                    <!-- Bucket -->
                    <path d="M125,145 L155,145 L151,180 L129,180 Z" fill="#fcfcf9"/>
                    <path d="M122,145 C122,130 158,130 158,145"/>
                    
                    <!-- Mop / Broom -->
                    <line x1="148" y1="210" x2="190" y2="105" stroke-dasharray="none" stroke-width="5"/>
                    <path d="M135,225 C140,200 155,200 160,225 Z" fill="#fcfcf9"/>
                    
                    <!-- Push Broom & Gloves -->
                    <line x1="180" y1="110" x2="210" y2="200" stroke-width="4"/>
                    <rect x="190" y="195" width="30" height="8" rx="2" fill="#fcfcf9"/>
                    <path d="M192,203 L192,215 M197,203 L197,215 M202,203 L202,215 M207,203 L207,215 M212,203 L212,215" stroke-width="2"/>
                </g>
                
                <!-- Text Arc: SALESCORE -->
                <path id="textArcTop" d="M 42 145 A 110 110 0 0 1 258 145" fill="none"/>
                <text fill="#e86d25" font-size="28" font-weight="900" font-family="'Segoe UI', Arial, sans-serif" letter-spacing="4">
                    <textPath href="#textArcTop" startOffset="50%" text-anchor="middle">SALESCORE</textPath>
                </text>
                
                <!-- Text Arc: SUPPLIES -->
                <path id="textArcBottom" d="M 258 160 A 110 110 0 0 1 42 160" fill="none"/>
                <text fill="#e86d25" font-size="28" font-weight="900" font-family="'Segoe UI', Arial, sans-serif" letter-spacing="4">
                    <textPath href="#textArcBottom" startOffset="50%" text-anchor="middle">SUPPLIES</textPath>
                </text>
            </svg>
        </div>
        
        <div class="brand-subtitle">Intelligent Stock &amp; Sales Analysis</div>
    </div>

    <form method="POST">
        <div class="input-group">
            <span class="input-icon"><i class="fa-solid fa-user"></i></span>
            <input type="text" name="username" placeholder="Username" required autocomplete="off">
        </div>

        <div class="input-group">
            <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" name="password" id="password" placeholder="Password" required>
            <span class="password-toggle" id="togglePassword">
                <i class="fa-regular fa-eye" id="eyeIcon"></i>
            </span>
        </div>

        <div class="forgot-link-wrapper">
            <a href="#" class="forgot-password-link" id="openForgotModal">Forgot Password?</a>
        </div>

        <button type="submit" class="btn-login">Log In</button>
    </form>
</div>

<div class="modal-overlay" id="forgotModal">
    <div class="modal-box">
        <div class="modal-title">Reset Password</div>
        <div class="modal-subtitle">Verify your administrator identity matching information to receive a secure recovery email link.</div>
        
        <form method="POST" id="forgotPasswordForm">
            <input type="hidden" name="action" value="forgot_password">

            <div class="input-group">
                <span class="input-icon"><i class="fa-solid fa-user"></i></span>
                <input type="text" name="forgot_username" placeholder="Username" required autocomplete="off">
            </div>

            <div class="input-group">
                <span class="input-icon"><i class="fa-solid fa-envelope"></i></span>
                <input type="email" name="forgot_email" placeholder="Recovery Email" required autocomplete="off">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="closeForgotModal">Cancel</button>
                <button type="submit" class="btn-submit-forgot">Send Link</button>
            </div>
        </form>
    </div>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    togglePassword.addEventListener('click', () => {
        const type = password.type === 'password' ? 'text' : 'password';
        password.type = type;
        eyeIcon.classList.toggle('fa-eye');
        eyeIcon.classList.toggle('fa-eye-slash');
    });

    const forgotModal = document.getElementById('forgotModal');
    const openForgotModal = document.getElementById('openForgotModal');
    const closeForgotModal = document.getElementById('closeForgotModal');
    const forgotPasswordForm = document.getElementById('forgotPasswordForm');
    const loadingOverlay = document.getElementById('loadingOverlay');

    openForgotModal.addEventListener('click', (e) => {
        e.preventDefault();
        forgotModal.classList.add('active');
    });

    closeForgotModal.addEventListener('click', () => {
        forgotModal.classList.remove('active');
    });

    forgotModal.addEventListener('click', (e) => {
        if (e.target === forgotModal) {
            forgotModal.classList.remove('active');
        }
    });

    forgotPasswordForm.addEventListener('submit', function() {
        forgotModal.classList.remove('active');
        loadingOverlay.classList.add('visible');
    });
</script>

</body>
</html>