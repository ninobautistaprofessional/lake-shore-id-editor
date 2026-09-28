#!/usr/bin/env python3
"""Apply all API.php fixes that the editor couldn't match due to quote escaping."""
import sys

f = open('backend/api.php', 'r', encoding='utf-8')
content = f.read()
f.close()

# ─── Fix 5: Duplicate prevention + name length validation in studentSaveCard ───
# Use a smaller, unique anchor that will match
old_block = """    $studentName=trim((string)($data['student_name']??''));
    $idType=trim((string)($data['id_type']??''));
    if ($studentName==='') response(['success'=>false,'message'=>'Please enter the student full name'],422);
    if (!in_array($idType,['COLLEGE','JUNIOR_HIGH','SENIOR_HIGH'],true)) response(['success'=>false,'message'=>'Please select a valid department'],422);
    $fields=['student_name','id_type','course','grade_level','section_name','student_number','student_id_number','lrn','academic_year','school_year','photo_path','address_line1','address_line2','emergency_label','emergency_contact','emergency_phone','terms_title','term_1','term_2','term_3','institution_name','institution_address','mobile_no','telephone_no','email_address','signatory_id','signatory_name','signature_path','template_id'];
    $values=[];foreach($fields as $f)$values[$f]=trim((string)($data[$f]??''));
    $cols=implode(',',$fields);$pars=implode(',',array_map(fn($f)=>\":$f\",$fields));
    $stmt=$pdo->prepare(\"INSERT INTO id_cards($cols) VALUES($pars)\");$stmt->execute($values);
    $id=(int)$pdo->lastInsertId();
    response(['success'=>true,'id'=>$id,'message'=>'Your ID request has been submitted successfully.']);
  }
  if ($action==='lostIdRequest'