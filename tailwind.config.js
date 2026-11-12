/** Identità visiva condivisa con scs-b2b: nero "ink", fondo "paper", titoli Archivo, testo Inter. */
module.exports = {
  content: ['./templates/**/*.twig', './assets/js/**/*.js', './src/**/*.php'],
  theme: {
    extend: {
      colors: {
        ink: '#111111',
        paper: '#f5f2ec',
        line: '#e4dfd6',
        muted: '#6b665e',
        accent: '#c8102e',
        ok: '#1f7a3f',
        warn: '#b45309',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        display: ['Archivo', 'Inter', 'system-ui', 'sans-serif'],
      },
      maxWidth: {
        page: '80rem',
      },
    },
  },
  plugins: [],
};
