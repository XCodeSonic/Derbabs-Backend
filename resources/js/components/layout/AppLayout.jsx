import ErrorBoundary from '../common/ErrorBoundary';
import Navigation from './Navigation';

const AppLayout = ({ children }) => (
  <div className="app-layout">
    <Navigation />
    <main className="main-content">
      <ErrorBoundary>{children}</ErrorBoundary>
    </main>
  </div>
);

export default AppLayout;
