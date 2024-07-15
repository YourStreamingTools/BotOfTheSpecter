var stripe = Stripe(''); // CHANGE TO MAKE THIS WORK
var checkoutButton = document.getElementById('checkout-button');
checkoutButton.addEventListener('click', function () {
    fetch('/payments/payment.php', {
        method: 'POST',
    })
    .then(function (response) {
        return response.json();
    })
    .then(function (session) {
        return stripe.redirectToCheckout({ sessionId: session.id });
    })
    .then(function (result) {
        if (result.error) {
            alert(result.error.message);
        }
    })
    .catch(function (error) {
        console.error('Error:', error);
    });
});

// Close notification
document.addEventListener('DOMContentLoaded', () => {
    (document.querySelectorAll('.notification .delete') || []).forEach(($delete) => {
        const $notification = $delete.parentNode;
        $delete.addEventListener('click', () => {
            $notification.parentNode.removeChild($notification);
        });
    });
});