// Function to convert UTC time to local time
function convertUTCToLocal(utcDateTime) {
  // Create a new Date object from the UTC date and time string
  const utcDate = new Date(utcDateTime);
  // Get the user's local time zone offset in minutes
  const offsetMinutes = utcDate.getTimezoneOffset();
  // Calculate the local date and time by adding the offset to the UTC date
  const localDate = new Date(utcDate.getTime() - offsetMinutes * 60 * 1000);
  return localDate;
}