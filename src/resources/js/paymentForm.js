function findClosestParent(startElement, fn) {
  var parent = startElement.parentElement;
  if (!parent) return undefined;
  return fn(parent) ? parent : findClosestParent(parent, fn);
}

function initEWay() {
  // Because this might get executed before eWay is loaded.
  if (typeof eCrypt === 'undefined') {
    setTimeout(initEWay, 200);
  } else {
    var $wrapper = document.querySelector('.eway-form');
    var $form = findClosestParent($wrapper, function (element) {
      return element.tagName === 'FORM';
    });
    var paymentFormNamespace = $wrapper.dataset.paymentFormNamespace;

    $form.addEventListener('submit', function (ev) {
      let $number = $form.querySelector(
        '[name="' + paymentFormNamespace + '[number]"]'
      );
      let $cvv = $form.querySelector('[name="' + paymentFormNamespace + '[cvv]"]');
      const key = $wrapper.dataset.key;

      if ($number) {
        const numInput = document.createElement('input');
        numInput.type = 'hidden';
        numInput.name = paymentFormNamespace + '[encryptedCardNumber]';
        numInput.value = eCrypt.encryptValue($number.value, key);
        $form.appendChild(numInput);
      }

      if ($cvv) {
        const cvvInput = document.createElement('input');
        cvvInput.type = 'hidden';
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
