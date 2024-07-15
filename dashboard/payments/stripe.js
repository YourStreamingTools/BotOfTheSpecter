document.addEventListener('DOMContentLoaded', () => {
    // Close notification
    (document.querySelectorAll('.notification .delete') || []).forEach(($delete) => {
        const $notification = $delete.parentNode;
        $delete.addEventListener('click', () => {
            $notification.parentNode.removeChild($notification);
        });
    });

    var stripe = Stripe(''); // CHANGE TO MAKE THIS WORK

    var checkoutButton = document.getElementById('checkout-button');
    if (checkoutButton) {
        checkoutButton.addEventListener('click', function () {
            fetch('/payments/subscribe.php', {
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
    }

    var cancelSubscriptionButton = document.getElementById('cancel-subscription-button');
    if (cancelSubscriptionButton) {
        cancelSubscriptionButton.addEventListener('click', function () {
            fetch('/payments/cancel_subscription.php', {
                method: 'POST',
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result.success) {
                    location.reload();
                } else {
                    alert('Failed to cancel subscription.');
                }
            })
            .catch(function (error) {
                console.error('Error:', error);
            });
        });
    }
});