$(document).ready(function() {
  $("#show-api-key").click(function() {
    $(".api-key-wrapper").show();
    $("#show-api-key").hide();
    $("#hide-api-key").show();
  });

  $("#hide-api-key").click(function() {
    $(".api-key-wrapper").hide();
    $("#show-api-key").show();
    $("#hide-api-key").hide();
  });
});

// Function to convert UTC date to local date in the desired format
function convertUTCToLocalFormatted(utcDateStr) {
  const options = {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    hour12: true,
    timeZoneName: 'short'
  };
  const utcDate = new Date(utcDateStr + ' UTC');
  const localDate = new Date(utcDate.toLocaleString('en-US', { timeZone: '<?php echo $timezone; ?>' }));
  const dateTimeFormatter = new Intl.DateTimeFormat('en-US', options);
  return dateTimeFormatter.format(localDate);
}

// PHP variables holding the UTC date and time
const signupDateUTC = "<?php echo $signup_date_utc; ?>";
const lastLoginUTC = "<?php echo $last_login_utc; ?>";

// Display the dates in the user's local time zone
document.getElementById('localSignupDate').innerText = convertUTCToLocalFormatted(signupDateUTC);
document.getElementById('localLastLogin').innerText = convertUTCToLocalFormatted(lastLoginUTC);