import { useState } from 'react';
import { cn } from '../lib/utils';
import type { ArchView } from '../data/architecture';
import { Card, CardContent } from './ui/card';

export function ArchitectureDiagram({ view }: { view: ArchView }) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;

    return (
        <div>
            <div className="relative h-[420px] w-full overflow-hidden rounded-2xl border border-console-border bg-console-surface">
                <svg className="absolute inset-0 h-full w-full" aria-hidden="true">
                    <defs>
                        <marker id={`arrow-${view.id}`} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="#c7c9e0" />
                        </marker>
                    </defs>
                    {view.edges.map((edge) => {
                        const from = view.nodes.find((node) => node.id === edge.from);
                        const to = view.nodes.find((node) => node.id === edge.to);
                        if (!from || !to) return null;

                        return (
                            <line
                                key={`${edge.from}-${edge.to}`}
                                x1={`${from.x}%`}
                                y1={`${from.y}%`}
                                x2={`${to.x}%`}
                                y2={`${to.y}%`}
                                stroke="#c7c9e0"
                                strokeWidth={1.5}
                                markerEnd={`url(#arrow-${view.id})`}
                            />
                        );
                    })}
                </svg>

                {view.nodes.map((node) => (
                    <button
                        key={node.id}
                        type="button"
                        onClick={() => setSelectedId(node.id)}
                        style={{ left: `${node.x}%`, top: `${node.y}%` }}
                        className={cn(
                            '-translate-x-1/2 -translate-y-1/2 absolute rounded-lg border bg-white px-3 py-1.5 text-xs font-medium shadow-sm transition-colors',
                            selectedId === node.id
                                ? 'border-console-accent text-console-accent'
                                : 'border-console-border text-console-text hover:border-console-accent/50',
                        )}
                    >
                        {node.label}
                    </button>
                ))}
            </div>

            <Card className="mt-4">
                <CardContent className="text-sm text-console-text">
                    {selected ? (
                        <>
                            <p className="font-semibold text-console-text">{selected.label}</p>
                            <p className="mt-1 text-console-text-muted">{selected.description}</p>
                        </>
                    ) : (
                        <p className="text-console-text-muted">Клікни на вузол, щоб побачити опис.</p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
