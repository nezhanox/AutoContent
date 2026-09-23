import { Link } from '@inertiajs/react';
import { Clapperboard, LayoutDashboard } from 'lucide-react';
import { cn } from '../lib/utils';

const NAV_ITEMS = [
    { href: '/console', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/console/videos', label: 'Videos', icon: Clapperboard },
];

export function Sidebar({ current }: { current: string }) {
    return (
        <aside className="flex h-screen w-18 flex-col items-center gap-1 border-r border-console-border bg-console-surface py-5">
            <Link
                href="/console"
                className="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-console-accent to-indigo-400 text-white shadow-sm"
                aria-label="AutoContent Console"
            >
                <Clapperboard className="h-5 w-5" strokeWidth={2.25} />
            </Link>

            <nav className="flex flex-col items-center gap-1">
                {NAV_ITEMS.map((item) => {
                    const active = current === item.href;
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            title={item.label}
                            className={cn(
                                'flex h-11 w-11 items-center justify-center rounded-xl transition-colors',
                                active
                                    ? 'bg-console-accent-soft text-console-accent'
                                    : 'text-console-text-muted hover:bg-console-bg hover:text-console-text',
                            )}
                        >
                            <Icon className="h-5 w-5" strokeWidth={2} />
                        </Link>
                    );
                })}
            </nav>
        </aside>
    );
}
