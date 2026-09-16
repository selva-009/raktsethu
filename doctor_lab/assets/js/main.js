// Auto-dismiss flash messages after 5 seconds.
document.addEventListener('DOMContentLoaded', function () {
  var flash = document.querySelector('.flash');
  if (flash) {
    setTimeout(function () {
      flash.style.transition = 'opacity 0.4s ease';
      flash.style.opacity = '0';
      setTimeout(function () { flash.remove(); }, 400);
    }, 5000);
  }
});
