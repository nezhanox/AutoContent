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

export default function Dashboard({ stats, bestVideos, topTopics }: DashboardProps) {
    return (
        <AppLayout>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                <Stat label="Videos generated today" value={stats.videosGeneratedToday} />
                <Stat label="Videos published" value={stats.videosPublished} />
                <Stat label="Failed jobs today" value={stats.failedJobsToday} />
                <Stat label="Views" value={stats.views} />
                <Stat label="Likes" value={stats.likes} />
                <Stat label="Comments" value={stats.comments} />
            </div>

            <div className="mt-6 grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Best Videos</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableHeadCell>Video</TableHeadCell>
                                    <TableHeadCell>Platform</TableHeadCell>
                                    <TableHeadCell>Views</TableHeadCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {bestVideos.map((video) => (
                                    <TableRow key={video.id}>
                                        <TableCell>{video.video_title}</TableCell>
                                        <TableCell><Badge>{video.platform}</Badge></TableCell>
                                        <TableCell>{video.views}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Top Topics</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableHeadCell>Topic</TableHeadCell>
                                    <TableHeadCell>Views</TableHeadCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {topTopics.map((topic) => (
                                    <TableRow key={topic.topic}>
                                        <TableCell>{topic.topic}</TableCell>
                                        <TableCell>{topic.total_views}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardContent>
                <p className="text-xs font-medium text-gray-500">{label}</p>
                <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
            </CardContent>
        </Card>
    );
}
