<?php
// SF3 RENDERING SECTION FIX
?>
                                        <!-- Header Row 1 -->
                                        <tr style="background: #f0f0f0;">
                                            <td rowspan="3" style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 10px; width: 40px; vertical-align: middle;">No.</td>
                                            <td rowspan="3" style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 10px; width: 300px; vertical-align: middle;">NAME OF LEARNERS<br><span style="font-size: 8px;">(Surname, First Name, Middle Name)</span></td>
                                            <?php foreach ($inventory as $book): ?>
                                                <td colspan="2" style="border: 1px solid #000; text-align: center; font-size: 8px; background: #f0f0f0; padding: 2px;">Subject Area & Title</td>
                                            <?php endforeach; ?>
                                            <td rowspan="3" colspan="2" style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 8px; width: 60px; vertical-align: middle;">TOTAL<br>COPIES</td>
                                            <td rowspan="3" style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 8px; width: 110px; vertical-align: middle; white-space: normal;">REMARKS/ACTION TAKEN<br>(Please refer to the legend on last page)</td>
                                        </tr>

                                        <!-- Header Row 2: Book Subject + Title (Vertical) -->
                                        <tr style="background: #f0f0f0;">
                                            <?php foreach ($inventory as $book): ?>
                                                <td colspan="2" class="form-vertical-text">
                                                    <?= htmlspecialchars($book['subject']) ?> - <?= htmlspecialchars($book['title']) ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>

                                        <!-- Header Row 3: Date Issued / Date Returned -->
                                        <tr style="background: #f0f0f0;">
                                            <?php foreach ($inventory as $book): ?>
                                                <td style="border: 1px solid #000; text-align: center; font-size: 8px; width: 45px; padding: 2px;">Date<br>Issued</td>
                                                <td style="border: 1px solid #000; text-align: center; font-size: 8px; width: 45px; padding: 2px;">Date<br>Returned</td>
                                            <?php endforeach; ?>
                                        </tr>

                                        <!-- Male Section -->
                                        <tr><td colspan="<?= (count($inventory) * 2) + 6 ?>" style="border: 2px solid #000; font-weight: bold; background: #e0e0e0; padding: 3px; font-size: 10px;">MALE</td></tr>
                                        <?php 
                                        $male_count = 0;
                                        $male_issued_per_book = [];
                                        $male_returned_per_book = [];
                                        foreach ($students as $student): 
                                            if (trim(strtoupper($student['gender'])) !== 'MALE') continue;
                                            $male_count++;
                                            $remarksList = [];
                                            $student_total_issued = 0;
                                            $student_total_returned = 0;
                                        ?>
                                        <tr>
                                            <td style="border: 1px solid #000; text-align: center; font-size: 10px;"><?= $male_count ?></td>
                                            <td style="border: 1px solid #000; padding: 2px 5px; font-size: 10px; text-transform: uppercase;"><?= htmlspecialchars($student['student_name']) ?></td>
                                            <?php foreach ($inventory as $book): 
                                                $record = $student['books'][$book['id']] ?? null;
                                                $issued = ''; $returned = ''; $cell_class = ''; $tooltip = '';
                                                if ($record) {
                                                    $i_date = $record['date_issued'] ?: ($report['bosy_date'] ?? null);
                                                    $r_date = $record['date_returned'] ?: ($report['eosy_date'] ?? null);
                                                    if ($i_date) {
                                                        $issued = date('n/j', strtotime($i_date));
                                                        $student_total_issued++;
                                                        $male_issued_per_book[$book['id']] = ($male_issued_per_book[$book['id']] ?? 0) + 1;
                                                    }
                                                    if ($r_date && !empty($record['condition_returned'])) {
                                                        $returned = date('n/j', strtotime($r_date));
                                                        $student_total_returned++;
                                                        $male_returned_per_book[$book['id']] = ($male_returned_per_book[$book['id']] ?? 0) + 1;
                                                    }
                                                    if (!empty($record['remarks'])) $remarksList[] = $book['subject'] . ': ' . $record['remarks'];
                                                    elseif (!empty($record['condition_returned']) && $record['condition_returned'] !== 'Good') $remarksList[] = $book['subject'] . ': ' . $record['condition_returned'];

                                                    $b_status = $record['status'] ?? 'Issued';
                                                    $b_cond = $record['condition_returned'] ?? '';
                                                    if ($b_status === 'Returned') $cell_class = 'text-success fw-bold';
                                                    elseif (in_array($b_cond, ['Missing', 'Damaged', 'Lost'])) $cell_class = 'text-danger fw-bold';
                                                    else $cell_class = 'text-warning fw-bold';
                                                    $tooltip = "Book: " . htmlspecialchars($record['book_title'] ?? $book['title']) . " | Status: " . $b_status;
                                                }
                                            ?>
                                                <td style="border: 1px solid #000; text-align: center; padding: 2px; font-size: 9px; cursor:pointer;" class="<?= $cell_class ?>" title="<?= $tooltip ?>" data-bs-toggle="tooltip" onclick="openBookModal('<?= $student['lrn'] ?>', '<?= addslashes($student['student_name']) ?>', <?= $book['id'] ?>, '<?= addslashes($book['title']) ?>', <?= htmlspecialchars(json_encode($record)) ?>)">
                                                    <?= $issued ?>
                                                </td>
                                                <td style="border: 1px solid #000; text-align: center; padding: 2px; font-size: 9px; cursor:pointer;" class="<?= $cell_class ?>" title="<?= $tooltip ?>" data-bs-toggle="tooltip" onclick="openBookModal('<?= $student['lrn'] ?>', '<?= addslashes($student['student_name']) ?>', <?= $book['id'] ?>, '<?= addslashes($book['title']) ?>', <?= htmlspecialchars(json_encode($record)) ?>)">
                                                    <?= $returned ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td style="border: 1px solid #000; text-align: center; font-size: 10px; font-weight: bold;"><?= $student_total_issued ?></td>
                                            <td style="border: 1px solid #000; text-align: center; font-size: 10px; font-weight: bold;"><?= $student_total_returned ?></td>
                                            <td style="border: 1px solid #000; padding: 2px 5px; font-size: 8px; white-space: normal; line-height: 1.1;"><?= implode('; ', $remarksList) ?></td>
                                        </tr>
                                        <?php endforeach; ?>

                                        <!-- Female Section (similar structure...) -->
