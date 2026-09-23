import { ComponentType } from 'react';
import { Clapperboard, Eye, Heart, MessageCircle, Send, TriangleAlert } from 'lucide-react';
import { AppLayout } from '../components/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card';
import { Badge } from '../components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../components/ui/table';

interface Stats {
    videosGeneratedToday: number;
    videosPublished: number;
    failedJobsToday: number;
    views: number;
    likes: number;
    comments: number;
}

interface BestVideo {
    id: number;
    video_title: string;
    platform: string;
    views: number;
    likes: number;
    comments: number;
}

interface TopTopic {
    topic: string;
    total_views: number;
}

interface DashboardProps {
    stats: Stats;
    bestVideos: BestVideo[];
    topTopics: TopTopic[];
}

const STAT_TILES: Array<{ key: keyof Stats; label: string; icon: ComponentType<{ className?: string; strokeWidth?: number }> }> = [
    { key: 'videosGeneratedToday', label: 'Generated today', icon: Clapperboard },
    { key: 'videosPublished', label: 'Published', icon: Send },
    { key: 'failedJobsToday', label: 'Failed today', icon: TriangleAlert },
    { key: 'views', label: 'Views', icon: Eye },
    { key: 'likes', label: 'Likes', icon: Heart },
    { key: 'comments', label: 'Comments', icon: MessageCircle },
];

export default function Dashboard({ stats, bestVideos, topTopics }: DashboardProps) {
    return (
        <AppLayout title="Dashboard">
            <section>
                <h2 className="mb-4 text-sm font-semibold text-console-text">Overview</h2>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    {STAT_TILES.map((tile) => (
                        <StatTile key={tile.key} label={tile.label} value={stats[tile.key]} icon={tile.icon} />
                    ))}
                </div>
            </section>

            <section className="mt-8">
                <h2 className="mb-4 text-sm font-semibold text-console-text">Performance</h2>
                <div className="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Best videos</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHead>
                                    <TableRow>
                                        <TableHeadCell className="pl-5">Video</TableHeadCell>
                                        <TableHeadCell>Platform</TableHeadCell>
                                        <TableHeadCell className="pr-5 text-right">Views</TableHeadCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {bestVideos.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={3} className="px-5 py-6 text-center text-console-text-muted">
                                                No published videos with metrics yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {bestVideos.map((video) => (
                                        <TableRow key={video.id}>
                                            <TableCell className="pl-5">{video.video_title}</TableCell>
                                            <TableCell>
                                                <Badge>{video.platform}</Badge>
                                            </TableCell>
                                            <TableCell className="pr-5 text-right tabular-nums">
                                                {video.views.toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Top topics</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHead>
                                    <TableRow>
                                        <TableHeadCell className="pl-5">Topic</TableHeadCell>
                                        <TableHeadCell className="pr-5 text-right">Views</TableHeadCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {topTopics.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={2} className="px-5 py-6 text-center text-console-text-muted">
                                                No topic data yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {topTopics.map((topic) => (
                                        <TableRow key={topic.topic}>
                                            <TableCell className="pl-5">{topic.topic}</TableCell>
                                            <TableCell className="pr-5 text-right tabular-nums">
                                                {Number(topic.total_views).toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </AppLayout>
    );
}

function StatTile({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number;
    icon: ComponentType<{ className?: string; strokeWidth?: number }>;
}) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between p-4">
                <div>
                    <p className="text-xs font-medium text-console-text-muted">{label}</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums text-console-text">{value.toLocaleString()}</p>
                </div>
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-console-accent-soft text-console-accent">
                    <Icon className="h-4 w-4" strokeWidth={2.25} />
                </div>
            </CardContent>
        </Card>
    );
}
