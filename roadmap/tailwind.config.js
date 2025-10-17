/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./**/*.php",
    "./api/**/*.php"
  ],
  theme: {
    extend: {
      backdropBlur: {
        md: '12px',
      }
    },
  },
  plugins: [],
}
