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
    if (!$wrapper) {
      return;
    }
    var $form = findClosestParent($wrapper, function (element) {
      return element.tagName === 'FORM';
    });
    var paymentFormNamespace = $wrapper.dataset.paymentFormNamespace;

    $form.addEventListener('submit', function (ev) {
      // Only match the <input> itself: Craft's form markup also puts the name on the <craft-input> wrapper.
      let $number = $form.querySelector(
        'input[name="' + paymentFormNamespace + '[number]"]'
      );
      let $cvv = $form.querySelector(
        'input[name="' + paymentFormNamespace + '[cvv]"]'
      );
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

      // The confirmation form for saved payment sources only has a CVV field.
      if ($number) {
        $number.disabled = true;
      }

      if ($cvv) {
        $cvv.disabled = true;
      }
    });
  }
}

initEWay();
