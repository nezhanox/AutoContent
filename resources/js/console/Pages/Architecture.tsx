import { useState } from 'react';
import { AppLayout } from '../components/AppLayout';
import { ArchitectureDiagram } from '../components/ArchitectureDiagram';
import { Button } from '../components/ui/button';
import { ARCHITECTURE_VIEWS } from '../data/architecture';

export default function Architecture() {
    const [activeId, setActiveId] = useState(ARCHITECTURE_VIEWS[0].id);
    const active = ARCHITECTURE_VIEWS.find((view) => view.id === activeId) ?? ARCHITECTURE_VIEWS[0];

    return (
        <AppLayout title="Architecture">
            <div className="mb-4 flex gap-2">
                {ARCHITECTURE_VIEWS.map((view) => (
                    <Button
                        key={view.id}
                        variant={view.id === activeId ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setActiveId(view.id)}
                    >
                        {view.title}
                    </Button>
                ))}
            </div>

            <ArchitectureDiagram view={active} />
        </AppLayout>
    );
}
