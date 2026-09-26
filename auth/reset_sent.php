<?php
/**
 * reset_sent.php — "Reset link sent" page, shown after a user asks for a
 * password-reset email on forgot_password.php.
 *
 * Same design as verify_notice.php: InfinityFree blocks server-side
 * email, so the email is sent from the USER'S BROWSER via EmailJS.
 * The security-critical parts (token generation, validation, password
 * change) all happen server-side; the browser only delivers the link.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/email.php';

// Session must hold a pending reset (set by forgot_password.php).
// It is only set when the email matched a verified account — the page
// looks identical either way so it can't be used to probe which
// emails are registered.
$pending = $_SESSION['pending_reset'] ?? null;
if (!$pending) {
    redirect('/thalassemia/auth/forgot_password.php');
}

// Build the absolute reset link (works on http and https hosts)
$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO']
          : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$resetLink = $scheme . '://' . $host . '/thalassemia/auth/reset_password.php?token=' . rawurlencode($pending['token'] ?? '');

$pageTitle = 'Reset Link Sent';
require __DIR__ . '/../includes/header.php';
?>

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
  <p class="auth-subtitle">Reset Link Sent</p>
  <p class="auth-tagline">Check your inbox to continue</p>

  <p class="muted" style="font-size:0.85rem;margin-bottom:16px;">
    Press the button below and we'll email you a password reset link.
    The link is valid for <strong>1 hour</strong> and can be used once.
  </p>

  <?php if (!empty($pending['token'])): ?>
    <button type="button" class="btn-primary" id="sendEmailBtn" style="width:100%;">
      ✉️ Send Reset Email
    </button>

    <p id="sendStatus" class="muted" style="font-size:0.8rem;margin-top:12px;text-align:center;"></p>

    <p class="muted" style="font-size:0.75rem;margin-top:18px;">
      Didn't get the email? Check your spam folder, then press the button again.
    </p>
  <?php else: ?>
    <div class="flash flash-success" style="margin-bottom:14px;">
      If an account exists for that email, a reset link is on its way. Check your inbox.
    </div>
  <?php endif; ?>

  <a href="/thalassemia/auth/login.php" class="admin-login-link" style="display:block;margin-top:12px;">
    ← Back to Login
  </a>
</div>

<?php if (!empty($pending['token'])): ?>
<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
<script>
(function () {
  var PUBLIC_KEY  = <?= json_encode(EMAILJS_PUBLIC_KEY) ?>;
  var SERVICE_ID  = <?= json_encode(EMAILJS_SERVICE_ID) ?>;
  var TEMPLATE_ID = <?= json_encode(EMAILJS_RESET_TEMPLATE_ID) ?>;

  var btn    = document.getElementById('sendEmailBtn');
  var status = document.getElementById('sendStatus');

  function sendReset() {
    if (PUBLIC_KEY.indexOf('YOUR_') === 0) {
      status.textContent = 'Email sending is not configured yet — fill in config/email.php (guide: README-EMAIL.txt).';
      status.style.color = '#b45309';
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Sending…';
    status.textContent = '';
    emailjs.init({ publicKey: PUBLIC_KEY });
    emailjs.send(SERVICE_ID, TEMPLATE_ID, {
      to_name:     <?= json_encode($pending['name'] ?? 'there') ?>,
      to_email:    <?= json_encode($pending['email'] ?? '') ?>,
      reset_link:  <?= json_encode($resetLink) ?>,
      subject:     'Reset your RaktSethu password'
    }).then(function () {
      btn.disabled = false;
      btn.textContent = '✉️ Resend Reset Email';
      status.textContent = '✓ Email sent! Check your inbox (and spam folder).';
      status.style.color = '#059669';
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = '✉️ Try Sending Again';
      status.textContent = 'Could not send the email (' + (err && err.status ? 'error ' + err.status : 'network error') + '). Press try again.';
      status.style.color = '#b91c1c';
    });
  }

  btn.addEventListener('click', sendReset);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
