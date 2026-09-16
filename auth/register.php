<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$role = $_GET['role'] ?? $_POST['role'] ?? 'patient';
if (!in_array($role, ['patient', 'donor', 'doctor_lab'], true)) {
    $role = 'patient';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // --- Anti-spam: Rate limiting (max 3 per IP per hour) ---
    // Wrapped in try-catch so registration still works even if the
    // registration_attempts table hasn't been created yet.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $attemptCount = 0;
    try {
        $rateStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM registration_attempts WHERE ipAddress = ? AND attemptedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $rateStmt->execute([$clientIp]);
        $attemptCount = (int) $rateStmt->fetchColumn();

        if ($attemptCount >= 10) {
            $errors[] = 'Too many registration attempts. Please try again after an hour.';
        }

        // Log this attempt
        $pdo->prepare('INSERT INTO registration_attempts (ipAddress) VALUES (?)')->execute([$clientIp]);
    } catch (Throwable $e) {
        // Table doesn't exist yet — skip rate limiting, continue normally
    }

    // --- Anti-spam: Honeypot field (hidden from humans, bots fill it) ---
    $honeypot = trim($_POST['website_url'] ?? '');
    if ($honeypot !== '') {
        // Silently reject — pretend it worked but don't create account
        setFlash('success', 'Account created. Please log in.');
        redirect('/thalassemia/auth/login.php');
    }

    // --- Anti-spam: Math CAPTCHA ---
    $captchaAnswer = trim($_POST['captchaAnswer'] ?? '');
    $captchaExpected = $_POST['captchaExpected'] ?? '';
    if ($captchaAnswer === '' || $captchaExpected === '' || (int)$captchaAnswer !== (int)$captchaExpected) {
        $errors[] = 'Security check failed. Please solve the math problem correctly.';
    }

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $bloodGroup = $_POST['bloodGroup'] ?? '';
    $location   = $_POST['location'] ?? '';
    $securityQuestion = trim($_POST['securityQuestion'] ?? '');
    $securityAnswer   = trim($_POST['securityAnswer'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Please fill all required fields.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        $errors[] = 'Please enter a valid 10-digit Indian mobile number (starts with 6, 7, 8, or 9).';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter (a-z).';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number (0-9).';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character (e.g. !@#$%^&*).';
    }

    $validGroups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
    $cities = ['Mumbai','Delhi','Bengaluru','Chennai','Kolkata','Hyderabad','Pune','Ahmedabad','Jaipur','Lucknow','Kanpur','Nagpur','Indore','Bhopal','Patna','Vadodara','Ghaziabad','Ludhiana','Agra','Nashik','Faridabad','Meerut','Rajkot','Varanasi','Srinagar','Aurangabad','Dhanbad','Amritsar','Navi Mumbai','Allahabad','Ranchi','Howrah','Coimbatore','Jabalpur','Gwalior','Vijayawada','Jodhpur','Madurai','Raipur','Kota','Guwahati','Chandigarh','Thiruvananthapuram','Visakhapatnam','Other'];

    if ($role === 'patient') {
        if (!in_array($bloodGroup, $validGroups, true)) $errors[] = 'Please select a valid blood group.';
        if (!in_array($location, $cities, true)) $errors[] = 'Please select your city from the list.';
        if (trim($_POST['thalassemiaType'] ?? '') === '') $errors[] = 'Please enter your thalassemia type.';
    } elseif ($role === 'donor') {
        if (!in_array($bloodGroup, $validGroups, true)) $errors[] = 'Please select a valid blood group.';
        if (!in_array($location, $cities, true)) $errors[] = 'Please select your city from the list.';
    } elseif ($role === 'doctor_lab') {
        if (trim($_POST['labLicenseNo'] ?? '') === '') $errors[] = 'Please enter your lab/license number.';
        if (trim($_POST['affiliation'] ?? '') === '') $errors[] = 'Please enter your affiliation.';
        if (!in_array($location, $cities, true)) $errors[] = 'Please select your city from the list.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT userId FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) $errors[] = 'An account with that email already exists.';
    }
    if (!$errors) {
        $checkPhone = $pdo->prepare('SELECT userId FROM users WHERE phone = ?');
        $checkPhone->execute([$phone]);
        if ($checkPhone->fetch()) $errors[] = 'An account with this phone number already exists.';
    }

    // Validate security question
    $validQuestions = [
        'What was the name of your first school?',
        'What is your mother\'s maiden name?',
        'What was the name of your first pet?',
        'What is your favourite book?',
        'What city were you born in?'
    ];
    if (!in_array($securityQuestion, $validQuestions, true)) {
        $errors[] = 'Please select a valid security question.';
    }
    if (strlen($securityAnswer) < 2) {
        $errors[] = 'Please enter a security answer (at least 2 characters).';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $secAnswerHash = password_hash(strtolower($securityAnswer), PASSWORD_BCRYPT);
            // Generate email verification token (32 random bytes → 64 hex chars)
            $verifyToken = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO users (name, email, phone, passwordHash, role, securityQuestion, securityAnswer, emailVerified, verificationToken, verificationTokenAt) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())')->execute([$name, $email, $phone, $hash, $role, $securityQuestion, $secAnswerHash, $verifyToken]);
            $userId = (int) $pdo->lastInsertId();
            if ($role === 'patient') {
                $pdo->prepare('INSERT INTO patients (userId, bloodGroup, thalassemiaType, location) VALUES (?, ?, ?, ?)')->execute([$userId, $bloodGroup, trim($_POST['thalassemiaType']), $location]);
            } elseif ($role === 'donor') {
                $pdo->prepare('INSERT INTO donors (userId, bloodGroup, location) VALUES (?, ?, ?)')->execute([$userId, $bloodGroup, $location]);
            } elseif ($role === 'doctor_lab') {
                $pdo->prepare('INSERT INTO doctors_labs (userId, labLicenseNo, affiliation, license_state_council, license_year, specialty, verification_status) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$userId, trim($_POST['labLicenseNo']), trim($_POST['affiliation']), trim($_POST['stateCouncil'] ?? ''), trim($_POST['licenseYear'] ?? ''), trim($_POST['specialty'] ?? ''), 'pending_verification']);
            }
            $pdo->commit();
            // Store pending verification in session so verify_notice.php can send the email
            $_SESSION['pending_verification'] = [
                'userId' => $userId,
                'name'   => $name,
                'email'  => $email,
                'token'  => $verifyToken,
            ];
            redirect('/thalassemia/auth/verify_notice.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

$pageTitle = 'Register';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-logo">
      <span class="auth-logo-icon heartbeat">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2.5 C12 2.5, 19 9, 19 14 C19 17.5, 16 20, 12 20 C8 20, 5 17.5, 5 14 C5 9, 12 2.5, 12 2.5Z"/>
          <path d="M8 14 Q12 11, 16 14" stroke-width="1.5"/>
        </svg>
      </span>
      <span class="auth-logo-text">Rakt<span class="accent">Sethu</span></span>
    </div>
    <p class="auth-subtitle">Create your account</p>

    <div class="role-tabs">
      <a class="<?= $role === 'patient' ? 'active' : '' ?>" href="?role=patient">Patient</a>
      <a class="<?= $role === 'donor' ? 'active' : '' ?>" href="?role=donor">Donor</a>
      <a class="<?= $role === 'doctor_lab' ? 'active' : '' ?>" href="?role=doctor_lab">Doctor / Lab</a>
    </div>

    <?php foreach ($errors as $error): ?>
      <div class="flash flash-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="?role=<?= h($role) ?>" id="registerForm">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="role" value="<?= h($role) ?>">

      <label>Full Name *
        <input type="text" name="name" required value="<?= h($_POST['name'] ?? '') ?>" placeholder="Enter your full name">
      </label>

      <label>Email *
        <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@example.com">
      </label>

      <label>Phone Number (10 digits) *
        <div class="phone-input-wrap">
          <span class="phone-prefix">
            <svg width="18" height="14" viewBox="0 0 24 18" xmlns="http://www.w3.org/2000/svg">
              <rect width="24" height="6" y="0" fill="#ff9933"/>
              <rect width="24" height="6" y="6" fill="#fff"/>
              <rect width="24" height="6" y="12" fill="#138808"/>
              <circle cx="12" cy="9" r="2.5" fill="none" stroke="#000080" stroke-width="0.8"/>
            </svg>
            +91
          </span>
          <input type="tel" name="phone" required maxlength="10" pattern="[6-9][0-9]{9}"
                 value="<?= h($_POST['phone'] ?? '') ?>" placeholder="9876543210"
                 oninput="this.value=this.value.replace(/[^0-9]/g,'')">
        </div>
      </label>

      <label>Password *
        <input type="password" name="password" required id="passwordField"
               placeholder="Min 8 chars, 1 uppercase, 1 number, 1 special"
               oninput="checkPasswordStrength(this.value)">
      </label>

      <div id="passwordStrength">
        <div style="height:4px;border-radius:2px;background:var(--border);overflow:hidden;">
          <div id="strengthBar" style="height:100%;width:0;border-radius:2px;transition:all 0.3s ease;"></div>
        </div>
        <p id="strengthText" style="font-size:0.7rem;margin-top:4px;color:var(--muted);"></p>
      </div>

      <?php
      $cityList = ['Mumbai','Delhi','Bengaluru','Chennai','Kolkata','Hyderabad','Pune','Ahmedabad','Jaipur','Lucknow','Kanpur','Nagpur','Indore','Bhopal','Patna','Vadodara','Ghaziabad','Ludhiana','Agra','Nashik','Faridabad','Meerut','Rajkot','Varanasi','Srinagar','Aurangabad','Dhanbad','Amritsar','Navi Mumbai','Allahabad','Ranchi','Howrah','Coimbatore','Jabalpur','Gwalior','Vijayawada','Jodhpur','Madurai','Raipur','Kota','Guwahati','Chandigarh','Thiruvananthapuram','Visakhapatnam','Other'];
      ?>

      <?php if ($role === 'patient'): ?>
        <label>Blood Group *
          <select name="bloodGroup" required>
            <option value="">Select blood group</option>
            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
              <option value="<?= $bg ?>" <?= (($_POST['bloodGroup'] ?? '') === $bg) ? 'selected' : '' ?>><?= $bg ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Thalassemia Type *
          <input type="text" name="thalassemiaType" required value="<?= h($_POST['thalassemiaType'] ?? '') ?>" placeholder="e.g. Beta Thalassemia Major">
        </label>
        <label>City *
          <select name="location" required>
            <option value="">Select your city</option>
            <?php foreach ($cityList as $city): ?>
              <option value="<?= $city ?>" <?= (($_POST['location'] ?? '') === $city) ? 'selected' : '' ?>><?= $city ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php elseif ($role === 'donor'): ?>
        <label>Blood Group *
          <select name="bloodGroup" required>
            <option value="">Select blood group</option>
            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
              <option value="<?= $bg ?>" <?= (($_POST['bloodGroup'] ?? '') === $bg) ? 'selected' : '' ?>><?= $bg ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>City *
          <select name="location" required>
            <option value="">Select your city</option>
            <?php foreach ($cityList as $city): ?>
              <option value="<?= $city ?>" <?= (($_POST['location'] ?? '') === $city) ? 'selected' : '' ?>><?= $city ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php elseif ($role === 'doctor_lab'): ?>
        <label>Lab / License Number *
          <input type="text" name="labLicenseNo" required value="<?= h($_POST['labLicenseNo'] ?? '') ?>" placeholder="Enter your NMC registration number">
        </label>
        <label>Affiliation (Hospital / Lab Name) *
          <input type="text" name="affiliation" required value="<?= h($_POST['affiliation'] ?? '') ?>" placeholder="e.g. AIIMS Delhi">
        </label>
        <label>City *
          <select name="location" required>
            <option value="">Select your city</option>
            <?php foreach ($cityList as $city): ?>
              <option value="<?= $city ?>" <?= (($_POST['location'] ?? '') === $city) ? 'selected' : '' ?>><?= $city ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <div class="security-section">
        <p class="security-info">🔐 Security Question — used for password recovery</p>
        <label>Choose a Security Question *
          <select name="securityQuestion" required>
            <option value="">Select a question</option>
            <option value="What was the name of your first school?" <?= (($_POST['securityQuestion'] ?? '') === 'What was the name of your first school?') ? 'selected' : '' ?>>What was the name of your first school?</option>
            <option value="What is your mother's maiden name?" <?= (($_POST['securityQuestion'] ?? '') === "What is your mother's maiden name?") ? 'selected' : '' ?>>What is your mother's maiden name?</option>
            <option value="What was the name of your first pet?" <?= (($_POST['securityQuestion'] ?? '') === 'What was the name of your first pet?') ? 'selected' : '' ?>>What was the name of your first pet?</option>
            <option value="What is your favourite book?" <?= (($_POST['securityQuestion'] ?? '') === 'What is your favourite book?') ? 'selected' : '' ?>>What is your favourite book?</option>
            <option value="What city were you born in?" <?= (($_POST['securityQuestion'] ?? '') === 'What city were you born in?') ? 'selected' : '' ?>>What city were you born in?</option>
          </select>
        </label>
        <label>Your Answer *
          <input type="text" name="securityAnswer" required value="<?= h($_POST['securityAnswer'] ?? '') ?>" placeholder="Enter your answer (remember this!)">
        </label>
      </div>

      <!-- Anti-spam: Math CAPTCHA -->
      <div class="captcha-box">
        <label class="captcha-label">Security Check: What is <strong id="captchaText">5 + 3 = ?</strong></label>
        <input type="number" name="captchaAnswer" required placeholder="Your answer" class="captcha-input">
      </div>

      <!-- Anti-spam: Honeypot (hidden — bots fill this, humans can't see it) -->
      <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
        <label>Website URL (leave empty)
          <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
        </label>
      </div>

      <button type="submit" class="btn-primary">Create Account</button>
    </form>

    <script>
    // Generate random math problem on page load
    (function(){
        var a = Math.floor(Math.random() * 9) + 1;
        var b = Math.floor(Math.random() * 9) + 1;
        var answer = a + b;
        document.getElementById('captchaText').textContent = a + ' + ' + b + ' = ?';
        // Store in session via hidden field — we'll set it in PHP below
        // Actually we need PHP to set the session. Let's use a hidden input.
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'captchaExpected';
        hidden.value = answer;
        document.getElementById('registerForm').appendChild(hidden);
    })();
    </script>

    <p class="auth-links">Already have an account? <a href="/thalassemia/auth/login.php">Log in</a></p>
  </div>
</div>

<script>
function checkPasswordStrength(password) {
    var bar = document.getElementById('strengthBar');
    var text = document.getElementById('strengthText');
    var score = 0;
    var checks = [];
    if (password.length >= 8) score += 20; else checks.push('8+ chars');
    if (/[A-Z]/.test(password)) score += 20; else checks.push('uppercase');
    if (/[a-z]/.test(password)) score += 20; else checks.push('lowercase');
    if (/[0-9]/.test(password)) score += 20; else checks.push('number');
    if (/[^A-Za-z0-9]/.test(password)) score += 20; else checks.push('special char');
    bar.style.width = score + '%';
    if (score < 40) { bar.style.background = '#dc2626'; text.style.color = '#dc2626'; text.textContent = 'Weak' + (checks.length ? ' - need: ' + checks.join(', ') : ''); }
    else if (score < 80) { bar.style.background = '#f59e0b'; text.style.color = '#b45309'; text.textContent = 'Medium' + (checks.length ? ' - need: ' + checks.join(', ') : ''); }
    else { bar.style.background = '#059669'; text.style.color = '#059669'; text.textContent = 'Strong password'; }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
