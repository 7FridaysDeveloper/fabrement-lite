import { createRoot } from 'react-dom/client';

import './index.css';
import { SiteTemplatesPage } from './pages/site-templates-page';

const root = document.getElementById('fabrement-templates-app-root');

if (root) {
  createRoot(root).render(<SiteTemplatesPage />);
}
