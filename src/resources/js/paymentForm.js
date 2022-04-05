function initEWay() {
  // Because this might get executed before eWay is loaded.
  if (typeof eCrypt === "undefined") {
    setTimeout(initEWay, 200);
  } else {
    var $wrapper = document.querySelector('.eway-form');
    var $form = document.querySelector('#paymentForm');
    var paymentFormNamespace = $wrapper.dataset.paymentFormNamespace;

    $form.addEventListener('submit', function (ev) {
      $number = $form.querySelector('[name="' + paymentFormNamespace + '[number]"]');
      $cvv = $form.querySelector('[name="' + paymentFormNamespace + '[cvv]"]');
      var key = $wrapper.dataset.key;

      if ($number) {
        var numInput = document.createElement('input');
        numInput.type = 'text';
        numInput.name = paymentFormNamespace + '[encryptedCardNumber]';
        numInput.value = eCrypt.encryptValue($number.value, key);
        $form.appendChild(numInput);
      }

      if ($cvv) {
        var cvvInput = document.createElement('input');
        cvvInput.type = 'text';
        cvvInput.name = paymentFormNamespace + '[encryptedCardCvv]';
        cvvInput.value = eCrypt.encryptValue($cvv.value, key);
        $form.appendChild(cvvInput);
      }

      $number.disabled = true;
      $cvv.disabled = true;
    });
  }
}

initEWay();