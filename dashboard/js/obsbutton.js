document.addEventListener('DOMContentLoaded', () => {
  // Show the modal when the button is clicked
  document.getElementById('show-obs-info').addEventListener('click', () => {
    document.getElementById('obsModal').classList.add('is-active');
  });

  // Hide the modal when the close button is clicked
  document.querySelectorAll('.modal-close, .modal-background').forEach((element) => {
    element.addEventListener('click', () => {
      document.getElementById('obsModal').classList.remove('is-active');
    });
  });
});