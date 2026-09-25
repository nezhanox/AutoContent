import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { AppLayout } from '../../components/AppLayout';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../../components/ui/table';

interface VideoScene {
    order: number;
    type: string;
    duration: number;
    text: string;
    visualQuery: string | null;
}

interface VideoDetail {
    id: number;
    title: string;
    description: string;
    status: string;
    stageLabel: string;
    stageColor: 'danger' | 'success' | 'warning' | 'default';
    canRetry: boolean;
    channel: string | null;
    channelId: number | null;
    idea: string | null;
    duration: number | null;
    width: number | null;
    height: number | null;
    createdAt: string | null;
    videoUrl: string | null;
    voiceoverUrl: string | null;
    musicAssetLabel: string | null;
    scriptText: string | null;
    subtitlesText: string | null;
    scenes: VideoScene[];
    qualityPassed: boolean | null;
    qualityReport: Record<string, unknown> | null;
    errorMessage: string | null;
    failedStage: string | null;
}

export default function VideoShow({ video }: { video: VideoDetail }) {
    const [retryError, setRetryError] = useState<string | null>(null);

    function retry() {
        router.post(`/console/videos/${video.id}/retry`, {}, {
            onError: (errors) => setRetryError(errors.video ?? 'Retry failed.'),
            onSuccess: () => setRetryError(null),
        });
    }

    return (
        <AppLayout title={video.title}>
            <div className="mb-5 flex items-center justify-between">
                <Link
                    href="/console/videos"
                    className="inline-flex items-center gap-1.5 text-sm text-console-text-muted hover:text-console-text"
                >
                    <ArrowLeft className="h-4 w-4" strokeWidth={2.25} />
                    All videos
                </Link>
                {video.canRetry && (
                    <Button variant="outline" size="sm" onClick={retry}>
                        <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.25} />
                        Retry
                    </Button>
                )}
            </div>

            {retryError && (
                <p className="mb-4 rounded-lg bg-console-danger-soft px-4 py-2 text-sm text-console-danger">{retryError}</p>
            )}

            <div className="space-y-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                        <div className="flex items-center gap-2">
                            <Badge>{video.status}</Badge>
                            <Badge variant={video.stageColor}>{video.stageLabel}</Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <div className="text-console-text-muted">Channel</div>
                            <div className="font-medium text-console-text">{video.channel ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Idea</div>
                            <div className="font-medium text-console-text">{video.idea ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Dimensions</div>
                            <div className="font-medium text-console-text">
                                {video.width && video.height ? `${video.width}×${video.height}` : '—'}
                            </div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Duration</div>
                            <div className="font-medium text-console-text">{video.duration ? `${video.duration}s` : '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Background music</div>
                            <div className="font-medium text-console-text">{video.musicAssetLabel ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Created</div>
                            <div className="font-medium text-console-text">{video.createdAt ?? '—'}</div>
                        </div>
                        {video.description && (
                            <div className="col-span-full">
                                <div className="text-console-text-muted">Description</div>
                                <div className="text-console-text">{video.description}</div>
                            </div>
                        )}
                        {video.errorMessage && (
                            <div className="col-span-full rounded-lg bg-console-danger-soft px-4 py-2 text-console-danger">
                                {video.failedStage ? `[${video.failedStage}] ` : ''}
                                {video.errorMessage}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Video</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {video.videoUrl ? (
                            <video controls preload="metadata" style={{ width: '100%', maxWidth: 360 }} src={video.videoUrl} />
                        ) : (
                            <p className="text-sm text-console-text-muted">Not rendered yet.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Voiceover</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {video.voiceoverUrl ? (
                            <audio controls preload="metadata" style={{ width: '100%', maxWidth: 480 }} src={video.voiceoverUrl} />
                        ) : (
                            <p className="text-sm text-console-text-muted">No voiceover yet.</p>
                        )}
                    </CardContent>
                </Card>

                {video.scriptText && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Script</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm text-console-text">{video.scriptText}</p>
                        </CardContent>
                    </Card>
                )}

                {video.scenes.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Scenes</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHead>
                                    <TableRow>
                                        <TableHeadCell className="pl-5">#</TableHeadCell>
                                        <TableHeadCell>Type</TableHeadCell>
                                        <TableHeadCell>Duration</TableHeadCell>
                                        <TableHeadCell>Text</TableHeadCell>
                                        <TableHeadCell className="pr-5">Visual query</TableHeadCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {video.scenes.map((scene) => (
                                        <TableRow key={scene.order}>
                                            <TableCell className="pl-5">{scene.order}</TableCell>
                                            <TableCell><Badge>{scene.type}</Badge></TableCell>
                                            <TableCell>{scene.duration}s</TableCell>
                                            <TableCell className="max-w-sm">{scene.text}</TableCell>
                                            <TableCell className="pr-5 text-console-text-muted">{scene.visualQuery ?? '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {video.subtitlesText && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Subtitles</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs text-console-text">{video.subtitlesText}</pre>
                        </CardContent>
                    </Card>
                )}

                {video.qualityReport && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Quality report</CardTitle>
                            <Badge variant={video.qualityPassed ? 'success' : 'danger'}>
                                {video.qualityPassed ? 'Passed' : 'Failed'}
                            </Badge>
                        </CardHeader>
                        <CardContent>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs text-console-text">
                                {JSON.stringify(video.qualityReport, null, 2)}
                            </pre>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
