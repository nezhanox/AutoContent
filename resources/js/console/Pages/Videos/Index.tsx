import { FormEvent, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Plus, RefreshCw } from 'lucide-react';
import { AppLayout } from '../../components/AppLayout';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Card, CardContent } from '../../components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../../components/ui/table';
import { Dialog, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select } from '../../components/ui/select';

interface VideoRow {
    id: number;
    channel: string | null;
    idea: string | null;
    title: string;
    status: string;
    stageLabel: string;
    stageColor: 'danger' | 'success' | 'warning' | 'default';
    canRetry: boolean;
    createdAt: string | null;
}

interface Channel {
    id: number;
    name: string;
}

interface VideosIndexProps {
    videos: VideoRow[];
    channels: Channel[];
}

export default function VideosIndex({ videos, channels }: VideosIndexProps) {
    const [open, setOpen] = useState(false);
    const [retryError, setRetryError] = useState<string | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        content_project_id: '',
        topic: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/console/videos/generate', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    function retry(videoId: number) {
        router.post(`/console/videos/${videoId}/retry`, {}, {
            onError: (errors) => setRetryError(errors.video ?? 'Retry failed.'),
            onSuccess: () => setRetryError(null),
        });
    }

    return (
        <AppLayout title="Videos">
            <section>
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-console-text">
                        All videos <span className="font-normal text-console-text-muted">({videos.length})</span>
                    </h2>
                    <Button onClick={() => setOpen(true)}>
                        <Plus className="h-4 w-4" strokeWidth={2.25} />
                        Generate video
                    </Button>
                </div>

                {retryError && (
                    <p className="mb-4 rounded-lg bg-console-danger-soft px-4 py-2 text-sm text-console-danger">{retryError}</p>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableHeadCell className="pl-5">Channel</TableHeadCell>
                                    <TableHeadCell>Idea</TableHeadCell>
                                    <TableHeadCell>Title</TableHeadCell>
                                    <TableHeadCell>Status</TableHeadCell>
                                    <TableHeadCell>Stage</TableHeadCell>
                                    <TableHeadCell className="pr-5"></TableHeadCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {videos.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="px-5 py-8 text-center text-console-text-muted">
                                            No videos yet — generate one to get started.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {videos.map((video) => (
                                    <TableRow key={video.id}>
                                        <TableCell className="pl-5">{video.channel}</TableCell>
                                        <TableCell className="max-w-xs truncate text-console-text-muted">{video.idea}</TableCell>
                                        <TableCell className="max-w-xs truncate font-medium">
                                            <Link href={`/console/videos/${video.id}`} className="hover:underline">
                                                {video.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Badge>{video.status}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={video.stageColor}>{video.stageLabel}</Badge>
                                        </TableCell>
                                        <TableCell className="pr-5">
                                            {video.canRetry && (
                                                <Button variant="outline" size="sm" onClick={() => retry(video.id)}>
                                                    <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.25} />
                                                    Retry
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </section>

            <Dialog open={open} onClose={() => setOpen(false)}>
                <DialogHeader>
                    <DialogTitle>Generate video</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1">
                        <Label htmlFor="channel">Channel</Label>
                        <Select
                            id="channel"
                            value={data.content_project_id}
                            onChange={(e) => setData('content_project_id', e.target.value)}
                        >
                            <option value="">Select a channel</option>
                            {channels.map((channel) => (
                                <option key={channel.id} value={channel.id}>{channel.name}</option>
                            ))}
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="topic">Topic</Label>
                        <Input
                            id="topic"
                            value={data.topic}
                            onChange={(e) => setData('topic', e.target.value)}
                        />
                        {errors.topic && <p className="text-sm text-console-danger">{errors.topic}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Start</Button>
                    </DialogFooter>
                </form>
            </Dialog>
        </AppLayout>
    );
}
