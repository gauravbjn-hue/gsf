(function () {
  function setStatus(form, message, isError) {
    var status = form.querySelector('.gsf-status');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.classList.toggle('is-error', Boolean(isError));
    status.classList.toggle('is-success', !isError);
  }

  function createOrder(payload) {
    var body = new URLSearchParams(payload);
    return fetch(GSFDonationForm.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: body,
    }).then(function (response) {
      return response.json();
    });
  }

  function confirmPayment(payload) {
    var body = new URLSearchParams(payload);
    return fetch(GSFDonationForm.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: body,
    }).then(function (response) {
      return response.json();
    });
  }

  function handleSubmit(event) {
    event.preventDefault();
    var form = event.currentTarget;
    setStatus(form, 'Creating payment order...', false);

    var payload = {
      action: 'gsf_create_razorpay_order',
      nonce: GSFDonationForm.nonce,
      donor_name: form.querySelector('[name="donor_name"]').value.trim(),
      donor_email: form.querySelector('[name="donor_email"]').value.trim(),
      donor_phone: form.querySelector('[name="donor_phone"]').value.trim(),
      donor_message: form.querySelector('[name="donor_message"]').value.trim(),
      amount: form.querySelector('[name="donation_amount"]').value.trim(),
      currency: form.dataset.currency || GSFDonationForm.currency,
    };

    createOrder(payload).then(function (response) {
      if (!response || !response.success) {
        setStatus(form, response && response.data ? response.data.message : 'Unable to create order.', true);
        return;
      }

      var orderId = response.data.order_id;
      var amount = response.data.amount;
      var options = {
        key: GSFDonationForm.keyId,
        amount: amount * 100,
        currency: response.data.currency,
        name: GSFDonationForm.companyName,
        description: 'Donation',
        order_id: orderId,
        prefill: {
          name: payload.donor_name,
          email: payload.donor_email,
          contact: payload.donor_phone,
        },
        handler: function (checkoutResponse) {
          setStatus(form, 'Confirming payment...', false);
          confirmPayment({
            action: 'gsf_confirm_razorpay_payment',
            nonce: GSFDonationForm.nonce,
            order_id: checkoutResponse.razorpay_order_id,
            payment_id: checkoutResponse.razorpay_payment_id,
            signature: checkoutResponse.razorpay_signature,
            donor_name: payload.donor_name,
            donor_email: payload.donor_email,
            donor_phone: payload.donor_phone,
            donor_message: payload.donor_message,
            amount: amount,
            currency: response.data.currency,
          }).then(function (confirmResponse) {
            if (confirmResponse && confirmResponse.success) {
              setStatus(form, 'Thank you! Your donation has been received.', false);
              form.reset();
            } else {
              setStatus(form, confirmResponse && confirmResponse.data ? confirmResponse.data.message : 'Payment confirmation failed.', true);
            }
          });
        },
        modal: {
          ondismiss: function () {
            setStatus(form, 'Payment cancelled. You can try again.', true);
          },
        },
      };

      var checkout = new window.Razorpay(options);
      checkout.open();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.gsf-donation-form');
    if (!forms.length) {
      return;
    }

    forms.forEach(function (form) {
      form.addEventListener('submit', handleSubmit);
    });
  });
})();
