import { PropsWithChildren } from 'react';
import { router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { Sidebar } from './Sidebar';
import { Button } from './ui/button';

interface AppLayoutProps extends PropsWithChildren {
    title: string;
}

export function AppLayout({ title, children }: AppLayoutProps) {
    const { url } = usePage();

    function logout() {
        router.post('/console/logout');
    }

    return (
        <div className="flex min-h-screen bg-console-bg">
            <Sidebar current={url === '/console' ? '/console' : '/console/videos'} />

            <div className="flex-1">
                <header className="flex items-center justify-between border-b border-console-border bg-console-surface px-8 py-5">
                    <h1 className="text-2xl font-semibold text-console-text">{title}</h1>
                    <Button variant="ghost" size="sm" onClick={logout}>
                        <LogOut className="h-4 w-4" strokeWidth={2} />
                        Sign out
                    </Button>
                </header>

                <main className="mx-auto max-w-6xl px-8 py-8">{children}</main>
            </div>
        </div>
    );
}
