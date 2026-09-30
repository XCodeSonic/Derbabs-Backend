import AppProviders from './providers/AppProviders';
import AppRouter from './router/AppRouter';
import '../styles/app.css';

const App = () => (
  <AppProviders>
    <AppRouter />
  </AppProviders>
);

export default App;
