document.addEventListener('DOMContentLoaded', function() {
  if (window.AndroidBridge) {
    AndroidBridge.setUser(123, "giri"); // Replace with dynamic user later
  }

  const scanBtn = document.getElementById('scan-btn');
  if(scanBtn){
    scanBtn.addEventListener('click', function(){
      AndroidBridge.openQRScanner();
    });
  }
});


