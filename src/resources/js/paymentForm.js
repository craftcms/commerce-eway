function initEWay() {
    // Because this might get executed before Stripe is loaded.
    if (typeof eCrypt === "undefined") {
        setTimeout(initEWay, 200);
    } else {
        var $wrapper = $('.eway-form');
        var $form = $wrapper.parents('form');

        $form.on('submit', function (ev) {
            $number = $form.find('[name=number]');
            $cvv = $form.find('[name=cvv]');
            var key = $wrapper.data('key');

            if ($number.length) {
                $form.append('<input type="hidden" name="encryptedCardNumber" value="'+eCrypt.encryptValue($number.val(), key)+'"/>');
            }
            if ($cvv.length) {
                $form.append('<input type="hidden" name="encryptedCardCvv" value="' + eCrypt.encryptValue($cvv.val(), key) + '"/>');
            }

            $number.prop('disabled', true);
            $cvv.prop('disabled', true);
        });

    }
}

initEWay();