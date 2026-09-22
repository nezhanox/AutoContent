import { PropsWithChildren } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button } from '../components/ui/button';

export function AppLayout({ children }: PropsWithChildren) {
    function logout() {
        router.post('/console/logout');
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="border-b bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <nav className="flex items-center gap-4 text-sm font-medium">
                        <Link href="/console" className="text-gray-900">Dashboard</Link>
                        <Link href="/console/videos" className="text-gray-500 hover:text-gray-900">Videos</Link>
                    </nav>
                    <Button variant="outline" size="sm" onClick={logout}>Sign out</Button>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-8">{children}</main>
        </div>
    );
}
