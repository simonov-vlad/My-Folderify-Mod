import { FolderProvider } from './FolderContext';
import Sidebar from './Sidebar';
import MediaAreaHeader from './MediaAreaHeader';

const App = () => {
    return (
        <FolderProvider>
            <Sidebar />
            <MediaAreaHeader />
        </FolderProvider>
    );
};

export default App;
