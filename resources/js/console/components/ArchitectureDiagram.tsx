import { useState } from 'react';
import { cn } from '../lib/utils';
import type { ArchView } from '../data/architecture';
import { Card, CardContent } from './ui/card';

/**
 * Pulls the line's endpoint back toward `from` by `pullBackPct` percentage
 * points, so the arrowhead marker lands in the gap before the target node's
 * button rather than underneath it (where it would be hidden).
 */
function shortenedEndpoint(from: { x: number; y: number }, to: { x: number; y: number }, pullBackPct = 4) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const length = Math.sqrt(dx * dx + dy * dy) || 1;
    return {
        x: to.x - (dx / length) * pullBackPct,
        y: to.y - (dy / length) * pullBackPct,
    };
}

export function ArchitectureDiagram({ view }: { view: ArchView }) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;

    return (
        <div>
            <div className="relative h-[420px] w-full overflow-x-auto rounded-2xl border border-console-border bg-console-surface">
                <div className="relative h-full min-w-[960px]">
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

                            const end = shortenedEndpoint(from, to);
                            const midX = (from.x + to.x) / 2;
                            const midY = (from.y + to.y) / 2;

                            return (
                                <g key={`${edge.from}-${edge.to}`}>
                                    <line
                                        x1={`${from.x}%`}
                                        y1={`${from.y}%`}
                                        x2={`${end.x}%`}
                                        y2={`${end.y}%`}
                                        stroke="#c7c9e0"
                                        strokeWidth={1.5}
                                        markerEnd={`url(#arrow-${view.id})`}
                                    />
                                    {edge.label && (
                                        <text
                                            x={`${midX}%`}
                                            y={`${midY}%`}
                                            textAnchor="middle"
                                            dy="-4"
                                            fontSize="9"
                                            fill="#6b7089"
                                            paintOrder="stroke"
                                            stroke="white"
                                            strokeWidth={3}
                                        >
                                            {edge.label}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>

                    {view.groups?.map((group) => (
                        <p
                            key={group.label}
                            style={{ left: '4%', top: `${group.y}%` }}
                            className="absolute text-xs font-medium text-console-text-muted"
                        >
                            {group.label}
                        </p>
                    ))}

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
