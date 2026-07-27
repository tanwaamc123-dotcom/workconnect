<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';

$source = $argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('Master cover sheet not found.');
$image = imagecreatefrompng($source);
if (!$image) throw new RuntimeException('Unable to read master cover sheet.');

$services = [
    ['Responsive Website Design','Website & App',1800,5],['Mobile App UI Design','Website & App',2400,7],['E-Commerce Store Setup','Website & App',3200,10],['Analytics Dashboard Design','Website & App',2800,7],
    ['Brand Identity System','Graphic Design',2200,7],['Logo Design Package','Graphic Design',1500,5],['Product Packaging Design','Graphic Design',1900,6],['Social Media Content Kit','Graphic Design',1200,4],
    ['Pitch Deck Presentation','Document Services',1800,5],['Resume & CV Design','Document Services',900,3],['Document Formatting','Document Services',700,2],['Thai-English Translation','Document Services',800,3],
    ['Event Photography','Media Production',3500,7],['Portrait Retouching','Media Production',600,2],['Product Photography','Media Production',2800,6],['Short Video Editing','Media Production',1600,4],
    ['Motion Graphics Intro','Media Production',2200,6],['Podcast Audio Editing','Media Production',1200,3],['Professional Voiceover','Media Production',1400,3],['Website Copywriting','Document Services',1300,4],
    ['SEO Content Strategy','Website & App',1800,7],['Digital Marketing Plan','Website & App',2000,7],['Data Analysis Report','Document Services',2400,6],['Bookkeeping Support','Document Services',1500,7],
    ['Virtual Assistant Service','Document Services',1000,5],['Customer Support Setup','Website & App',1800,7],['3D Product Rendering','Graphic Design',2600,8],['Interior Visualization','Graphic Design',3200,10],
    ['Custom Illustration','Graphic Design',1800,6],['App Icon Design','Graphic Design',1200,4],['Online Math Tutoring','Document Services',700,2],['English Conversation Lessons','Document Services',650,2],
    ['Programming Mentorship','Website & App',1800,5],['Cybersecurity Website Review','Website & App',3000,7],['Business Workflow Automation','Website & App',3500,10],['Business Strategy Consulting','Document Services',2500,7],
];

$output = dirname(__DIR__) . '/assets/images/services/demo';
if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) throw new RuntimeException('Unable to create cover directory.');
$sourceWidth = imagesx($image); $sourceHeight = imagesy($image);
$cellWidth = intdiv($sourceWidth, 6); $cellHeight = intdiv($sourceHeight, 6);
$cropHeight = (int) round($cellWidth * 0.625);
$cropTop = intdiv($cellHeight - $cropHeight, 2);

$pdo = db();
$sellers = $pdo->query("SELECT id FROM users WHERE is_demo=1 AND role_id=(SELECT id FROM roles WHERE name='seller') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
if (!$sellers) throw new RuntimeException('Install demo users before building the marketplace.');
$insert = $pdo->prepare("INSERT INTO services (seller_id,category_id,title,description,price,delivery_days,features,thumbnail,views,status,is_demo) VALUES (?,(SELECT id FROM categories WHERE name=?),?,?,?,?,?,?,?,'active',1)");
$update = $pdo->prepare("UPDATE services SET seller_id=?,category_id=(SELECT id FROM categories WHERE name=?),description=?,price=?,delivery_days=?,features=?,thumbnail=?,views=?,status='active' WHERE title=? AND is_demo=1");

$pdo->beginTransaction();
try {
    foreach ($services as $index => [$title,$category,$price,$days]) {
        $row = intdiv($index, 6); $column = $index % 6;
        $cover = imagecreatetruecolor(800, 500);
        imagecopyresampled($cover, $image, 0, 0, $column*$cellWidth, $row*$cellHeight+$cropTop, 800, 500, $cellWidth, $cropHeight);
        $filename = sprintf('%02d-%s.webp', $index+1, strtolower(preg_replace('/[^a-z0-9]+/i','-',$title)));
        imagewebp($cover, $output . '/' . $filename, 84);
        $path = 'assets/images/services/demo/' . $filename;
        $description = 'Professional ' . strtolower($title) . ' delivered with clear communication, thoughtful execution, and practical files ready for your project.';
        $features = "Project-ready deliverables\nTwo revision rounds\nClear progress updates";
        $seller = (int) $sellers[$index % count($sellers)];
        $views = 40 + (($index * 37) % 480);
        $exists = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE title=' . $pdo->quote($title) . ' AND is_demo=1')->fetchColumn();
        if ($exists) $update->execute([$seller,$category,$description,$price,$days,$features,$path,$views,$title]);
        else $insert->execute([$seller,$category,$title,$description,$price,$days,$features,$path,$views]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
echo count($services) . " demo services and covers are ready.\n";
