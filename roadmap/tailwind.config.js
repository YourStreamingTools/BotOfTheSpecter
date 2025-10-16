/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./views/**/*.ejs",
    "./routes/**/*.js",
    "./public/**/*.js",
    "./app.js"
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
