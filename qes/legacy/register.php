<?php 
include('db.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    //This will collect the input data from the form
    $username = trim ($_POST['username']);
    $email = trim ($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Secure hash
    $role = 'user';      // Default role
    $status = 'pending'; //this will need an admins approval

    // ✅ ADDED: Check if email already exists
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    $check_email->store_result();
    
    if ($check_email->num_rows > 0) {
        echo "<script>alert('❌ This email is already registered. Please use a different email or login.'); window.location='register.php';</script>";
        exit;
    }
    $check_email->close();

    //Prepare query to insert user
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, created_at)
                            VALUES (?,?,?,?,?, NOW())");
    $stmt->bind_param("sssss", $username, $email, $password, $role, $status);

        if ($stmt->execute()) 
        {
            echo "<script>alert('✅ Registration successful! Wait for admin approval.'); window.location='login.php';</script>";
        }
        else
        {
            // ✅ IMPROVED: Better error message
            echo "<script>alert('❌ Registration failed. Please try again.'); window.location='register.php';</script>";
        }

        $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SCHEDFIX</title>
    <!-- Google Fonts for modern geometric typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            background-color: #2b55f6;
            color: #111;
            position: relative;
            overflow-x: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* -------------------------------------------------------------
           Main Page Layout
        ------------------------------------------------------------- */
        .page-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1360px;
            min-height: 100vh;
            margin: 0 auto;
            padding: 40px 50px;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            align-items: center;
            gap: 40px;
        }

        /* -------------------------------------------------------------
           Left Branding Section
        ------------------------------------------------------------- */
        .brand-section {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 20px 20px 40px 10px;
        }

        .brand-logo-container {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 50px;
            text-decoration: none;
        }

        .logo-text {
            font-family: 'Montserrat', 'Outfit', sans-serif;
            font-size: clamp(38px, 4.5vw, 56px);
            font-weight: 900;
            color: #ffffff;
            letter-spacing: 3px;
            text-transform: uppercase;
            line-height: 1;
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .calendar-icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #0d1a33;
            color: #ffffff;
            border-radius: 12px;
            padding: 7px 9px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25);
            transition: transform 0.3s ease;
        }

        .calendar-icon-box:hover {
            transform: scale(1.05) rotate(-2deg);
        }

        .calendar-icon-box svg {
            width: clamp(32px, 3.8vw, 46px);
            height: clamp(32px, 3.8vw, 46px);
            display: block;
        }

        .brand-tagline {
            font-size: clamp(16px, 1.4vw, 19px);
            font-weight: 600;
            line-height: 1.65;
            color: #081d33;
            max-width: 490px;
            letter-spacing: 0.2px;
        }

        /* -------------------------------------------------------------
           Right Section / Register Card
        ------------------------------------------------------------- */
        .register-card-wrapper {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            width: 100%;
        }

        .register-card {
            background: #ffffff;
            border-radius: 32px;
            padding: 44px 42px 38px 42px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 60px rgba(10, 32, 85, 0.25), 0 8px 20px rgba(0, 0, 0, 0.08);
            position: relative;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .register-card:hover {
            box-shadow: 0 30px 70px rgba(10, 32, 85, 0.3), 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #3b5bf6;
            letter-spacing: -0.3px;
        }

        /* -------------------------------------------------------------
           Form Groups & Capsule Inputs
        ------------------------------------------------------------- */
        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
            letter-spacing: -0.1px;
        }

        .input-capsule {
            background-color: #d6d9df;
            border-radius: 30px;
            height: 50px;
            display: flex;
            align-items: center;
            padding: 0 18px;
            transition: all 0.25s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .input-capsule:focus-within {
            background-color: #e5e8ee;
            border-color: #3b5bf6;
            box-shadow: 0 0 0 4px rgba(59, 91, 246, 0.18);
        }

        .input-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #111827;
            flex-shrink: 0;
            margin-right: 12px;
        }

        .input-icon svg {
            width: 19px;
            height: 19px;
            fill: #111827;
        }

        .capsule-input {
            flex: 1;
            height: 100%;
            background: transparent;
            border: none;
            outline: none;
            font-family: inherit;
            font-size: 15px;
            font-weight: 500;
            color: #111827;
            width: 100%;
        }

        .capsule-input::placeholder {
            color: #71717a;
            font-weight: 400;
        }

        /* Password Toggle Button */
        .toggle-password-btn {
            background: transparent;
            border: none;
            outline: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6px;
            margin-left: 8px;
            color: #111827;
            border-radius: 50%;
            transition: transform 0.2s ease, opacity 0.2s ease;
            flex-shrink: 0;
        }

        .toggle-password-btn:hover {
            opacity: 0.75;
            transform: scale(1.1);
        }

        .toggle-password-btn svg {
            width: 20px;
            height: 20px;
            fill: #111827;
        }

        /* -------------------------------------------------------------
           Register Button & Footer Links
        ------------------------------------------------------------- */
        .register-btn {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, #385bf6 0%, #1f42e4 100%);
            color: #ffffff;
            border: none;
            border-radius: 30px;
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 20px;
            box-shadow: 0 8px 24px rgba(56, 91, 246, 0.4);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .register-btn:hover {
            background: linear-gradient(135deg, #4467ff 0%, #294cf0 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(56, 91, 246, 0.5);
        }

        .register-btn:active {
            transform: translateY(1px);
            box-shadow: 0 4px 14px rgba(56, 91, 246, 0.4);
        }

        .card-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13.5px;
            color: #64748b;
        }

        .card-footer a {
            color: #3b5bf6;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .card-footer a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* -------------------------------------------------------------
           Responsive Media Queries
        ------------------------------------------------------------- */
        @media (max-width: 980px) {
            .page-container {
                grid-template-columns: 1fr;
                gap: 35px;
                padding: 40px 24px;
                justify-items: center;
            }

            .brand-section {
                text-align: center;
                align-items: center;
                padding: 10px 0;
            }

            .brand-logo-container {
                justify-content: center;
                margin-bottom: 20px;
            }

            .brand-tagline {
                text-align: center;
            }

            .register-card-wrapper {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .register-card {
                padding: 32px 22px;
                border-radius: 24px;
            }

            .card-title {
                font-size: 21px;
            }

            .logo-text {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>

<!-- Main Split Container -->
<main class="page-container">
    
    <!-- Left Branding Section -->
    <section class="brand-section">
        <div class="brand-logo-container">
            <span class="logo-text">SCH</span>
            <div class="calendar-icon-box" title="SCHEDFIX">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <!-- Calendar Body -->
                    <rect x="2" y="4" width="20" height="18" rx="4" fill="none" stroke="#ffffff" stroke-width="2"/>
                    <!-- Top Binder Rings -->
                    <line x1="7" y1="1.5" x2="7" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <line x1="17" y1="1.5" x2="17" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <!-- Header Divider -->
                    <line x1="2" y1="9" x2="22" y2="9" stroke="#ffffff" stroke-width="1.8"/>
                    <!-- Calendar Grid Dots -->
                    <circle cx="6.5" cy="13" r="1.2" fill="#ffffff"/>
                    <circle cx="12" cy="13" r="1.2" fill="#ffffff"/>
                    <circle cx="17.5" cy="13" r="1.2" fill="#ffffff"/>
                    <circle cx="6.5" cy="17" r="1.2" fill="#ffffff"/>
                    <!-- Checkmark on Calendar -->
                    <path d="M10.5 17.5 L12.5 19.5 L18 14" fill="none" stroke="#00e5ff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <span class="logo-text">DFIX</span>
        </div>

        <p class="brand-tagline">
            Your all-in-one platform for managing, scheduling, and organizing campus events with ease and efficiency.
        </p>
    </section>

    <!-- Right Register Card Section -->
    <section class="register-card-wrapper">
        <div class="register-card">
            
            <header class="card-header">
                <h1 class="card-title">Create an Account</h1>
            </header>

            <form method="POST" action="register.php" autocomplete="on">
                <!-- Username Field -->
                <div class="form-group">
                    <label for="regUsername" class="form-label">Username:</label>
                    <div class="input-capsule">
                        <span class="input-icon">
                            <!-- User Avatar Icon -->
                            <svg viewBox="0 0 24 24">
                                <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            id="regUsername" 
                            name="username" 
                            class="capsule-input" 
                            placeholder="Choose a username" 
                            required 
                            autocomplete="username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Email Field -->
                <div class="form-group">
                    <label for="regEmail" class="form-label">Enter Your Email:</label>
                    <div class="input-capsule">
                        <span class="input-icon">
                            <!-- Email Mail Icon -->
                            <svg viewBox="0 0 24 24">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                        </span>
                        <input 
                            type="email" 
                            id="regEmail" 
                            name="email" 
                            class="capsule-input" 
                            placeholder="name@example.com" 
                            required 
                            autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="regPassword" class="form-label">Enter Your Password:</label>
                    <div class="input-capsule">
                        <span class="input-icon">
                            <!-- Lock Silhouette Icon -->
                            <svg viewBox="0 0 24 24">
                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            id="regPassword" 
                            name="password" 
                            class="capsule-input" 
                            placeholder="Create a password" 
                            required 
                            autocomplete="new-password">
                        <button 
                            type="button" 
                            class="toggle-password-btn" 
                            id="togglePasswordBtn"
                            onclick="togglePasswordVisibility()" 
                            title="Show password"
                            aria-label="Toggle password visibility">
                            <!-- Eye Icon -->
                            <svg id="eyeIcon" viewBox="0 0 24 24">
                                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="register-btn">
                    <span>Register</span>
                </button>
            </form>

            <footer class="card-footer">
                Already have an account? <a href="login.php">Login here</a>
            </footer>

        </div>
    </section>

</main>

<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('regPassword');
    const eyeIcon = document.getElementById('eyeIcon');
    const toggleBtn = document.getElementById('togglePasswordBtn');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleBtn.title = 'Hide password';
        eyeIcon.innerHTML = `
            <path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
        `;
    } else {
        passwordInput.type = 'password';
        toggleBtn.title = 'Show password';
        eyeIcon.innerHTML = `
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        `;
    }
}
</script>

</body>
</html>