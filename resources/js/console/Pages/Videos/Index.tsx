import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AppLayout } from '../../Components/AppLayout';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
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

    return (
        <AppLayout>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-semibold">Videos</h1>
                <Button onClick={() => setOpen(true)}>Generate Video</Button>
            </div>

            <Table>
                <TableHead>
                    <TableRow>
                        <TableHeadCell>Channel</TableHeadCell>
                        <TableHeadCell>Idea</TableHeadCell>
                        <TableHeadCell>Title</TableHeadCell>
                        <TableHeadCell>Status</TableHeadCell>
                        <TableHeadCell>Stage</TableHeadCell>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {videos.map((video) => (
                        <TableRow key={video.id}>
                            <TableCell>{video.channel}</TableCell>
                            <TableCell>{video.idea}</TableCell>
                            <TableCell>{video.title}</TableCell>
                            <TableCell><Badge>{video.status}</Badge></TableCell>
                            <TableCell><Badge variant={video.stageColor}>{video.stageLabel}</Badge></TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <Dialog open={open} onClose={() => setOpen(false)}>
                <DialogHeader>
                    <DialogTitle>Generate Video</DialogTitle>
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
                        {errors.topic && <p className="text-sm text-red-600">{errors.topic}</p>}
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
