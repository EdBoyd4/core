<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/constants/clarassign-constants-database-connectors.php';

class TrackerQueryManager {
    /**
     * Fetch trackers for a given entity type and ID.
     * 
     * @param string $entityType 'case', 'assignment', 'investigation', or 'task'
     * @param int $entityId The primary key of the entity
     * @param string $trackerType 'leads', 'docs', 'collateral', 'notes', 'deadlines', 'informants'
     * @return array
     */
    public static function getTrackers(string $entityType, int $entityId, string $trackerType): array {
        if (!in_array($entityType, ['case', 'assignment', 'investigation', 'task'])) {
            throw new InvalidArgumentException("Invalid entity type: $entityType");
        }

        $isCandA = in_array($entityType, ['case', 'assignment']);
        $db = $isCandA ? ClarassignDB::getCandACnx() : ClarassignDB::getIandTCnx();
        $toolsDb = ClarassignDB::getProductionToolsCnx();
        
        $entityIdCol = "{$entityType}_id";

        // Handle Local Trackers (Stored directly in domain DB)
        if (in_array($trackerType, ['deadlines'])) {
            $tableName = "{$entityType}_{$trackerType}";
            
            // Handle specific column names based on tracker type
            $cols = "*";
            $orderBy = "";
            if ($trackerType === 'deadlines') {
                $cols = "_id AS deadline_id, deadline_datetime, status";
                $orderBy = "ORDER BY deadline_datetime ASC";
            }

            // Execute local query
            $stmt = $db->prepare("SELECT $cols FROM $tableName WHERE $entityIdCol = ? $orderBy");
            if (!$stmt) return [];
            
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $results = [];
            while ($row = $res->fetch_assoc()) {
                $results[] = $row;
            }
            $stmt->close();
            return $results;
        }

        // Handle Fully Polymorphic Linked Tools (Junction & Target both in PRODUCTION_TOOLS)
        if (in_array($trackerType, ['individual', 'location_physical', 'location_virtual', 'organization', 'analysis', 'findings', 'subject_individuals', 'subject_locations', 'notes', 'docs'])) {
            $junctionTable = match($trackerType) {
                'individual', 'subject_individuals' => 'scopes_and_individuals_junction',
                'location_physical', 'subject_locations' => 'scopes_and_location_physical_junction',
                'location_virtual' => 'scopes_and_location_virtual_junction',
                'organization' => 'scopes_and_organizations_junction',
                'analysis' => 'scopes_and_analysis_junction',
                'findings' => 'scopes_and_findings_junction',
                'notes' => 'scopes_and_notes_junction',
                'docs' => 'scopes_and_documents_junction'
            };
            
            $toolIdCol = match($trackerType) {
                'individual', 'subject_individuals' => 'individuals_id',
                'location_physical', 'subject_locations' => 'location_id',
                'location_virtual' => 'location_virtual_id',
                'organization' => 'organization_id',
                'analysis' => 'analysis_id',
                'findings' => 'finding_id',
                'notes' => 'note_id',
                'docs' => 'document_id'
            };
            
            $extraJunctionCols = $trackerType === 'notes' ? ", note_type_id" : "";
            
            $stmt = $toolsDb->prepare("SELECT $toolIdCol $extraJunctionCols FROM $junctionTable WHERE scope_level_entry = ? AND entity_id = ?");
            if (!$stmt) return [];
            
            $stmt->bind_param('si', $entityType, $entityId);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $junctionData = [];
            $ids = [];
            while ($row = $res->fetch_assoc()) {
                $ids[] = $row[$toolIdCol];
                $junctionData[$row[$toolIdCol]] = $row;
            }
            $stmt->close();
            
            if (empty($ids)) return [];
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            
            $toolsQuery = match($trackerType) {
                'individual', 'subject_individuals' => "
                    SELECT s._id AS subject_individual_id, s.first_name, s.last_name, s.reference_name, s.description, s.datetime_of_entry,
                           p.dob, p.ssn
                    FROM individuals s
                    LEFT JOIN pii p ON s._id = p.individuals_id
                    WHERE s._id IN ($placeholders)
                ",
                'location_physical', 'subject_locations' => "SELECT _id AS subject_location_id, location_name, address_line_1, address_line_2, city, state, zip_code, description, datetime_of_entry FROM location_physical WHERE _id IN ($placeholders)",
                'location_virtual' => "SELECT _id AS location_virtual_id, virtual_address, description, datetime_of_entry FROM location_virtual WHERE _id IN ($placeholders)",
                'organization' => "SELECT _id AS organization_id, organization_name, description, datetime_of_entry FROM organizations WHERE _id IN ($placeholders)",
                'analysis' => "SELECT _id AS analysis_id, analysis_text, datetime_of_entry FROM analysis WHERE _id IN ($placeholders)",
                'findings' => "SELECT _id AS finding_id, finding_text, datetime_of_entry FROM findings WHERE _id IN ($placeholders)",
                'docs' => "SELECT _id AS document_id, document_name AS file_name, description FROM documents WHERE _id IN ($placeholders)",
                'notes' => "SELECT _id AS note_id, note_text, created_at AS datetime_of_entry FROM notes WHERE _id IN ($placeholders)"
            };
            
            $stmtTools = $toolsDb->prepare($toolsQuery);
            if (!$stmtTools) return [];
            
            $stmtTools->bind_param($types, ...$ids);
            $stmtTools->execute();
            $resTools = $stmtTools->get_result();
            
            $results = [];
            while ($row = $resTools->fetch_assoc()) {
                $id = $row[$toolIdCol];
                $mergedRow = array_merge($row, $junctionData[$id] ?? []);
                
                // For backward compatibility with existing views/partials
                if ($trackerType === 'notes') {
                    $mergedRow['case_note'] = $row['note_text'];
                    $mergedRow['assignment_note'] = $row['note_text'];
                    $mergedRow['investigation_note'] = $row['note_text'];
                    $mergedRow['task_note'] = $row['note_text'];
                    $mergedRow['text'] = $row['note_text'];
                    
                    // Map note_type_id to the legacy view expected keys
                    if (isset($mergedRow['note_type_id'])) {
                        $mergedRow['case_note_type'] = $mergedRow['note_type_id'];
                        $mergedRow['assignment_note_type'] = $mergedRow['note_type_id'];
                        $mergedRow['investigation_note_type'] = $mergedRow['note_type_id'];
                        $mergedRow['task_note_type'] = $mergedRow['note_type_id'];
                        $mergedRow['type'] = $mergedRow['note_type_id'];
                    }
                }
                $results[] = $mergedRow;
            }
            $stmtTools->close();
            return $results;
        }

        return [];
    }
}
