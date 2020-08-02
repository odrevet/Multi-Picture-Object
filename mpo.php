<?php
//Constants
define("MARKER_APP0", pack("n", 0xffe0));
define("MARKER_APP1", pack("n", 0xffe1));
define("MARKER_APP2", pack("n", 0xffe2));
define("MARKER_SOS", pack("n", 0xffda));
define("MARKER_SIZE", 2);
define("LEN_SIZE", 2);

/**
Locate APP0 APP1 APP2 position and length
@return Assosiative array with Marker pos and len
 */
function read_meta($img_data)
{
    $meta = array();
    //get the meta part of the image (until Start Of Scan)
    $SOS_pos = strpos($img_data, MARKER_SOS);
    $img_data_header = substr($img_data, 0, $SOS_pos);
    $pos = MARKER_SIZE; //after SOI

    $APP0_pos = strpos($img_data_header, MARKER_APP0);

    if ($APP0_pos) {
        $pos = $APP0_pos;
        $len_str = substr($img_data_header, $pos + MARKER_SIZE, LEN_SIZE);
        $len = unpack('n', ($len_str))[1];
        $meta['APP0']['pos'] = $pos;
        $meta['APP0']['len'] = $len;
    }

    $APP1_pos = strpos($img_data_header, MARKER_APP1, $pos);
    if ($APP1_pos) {
        $pos = $APP1_pos;
        $len_str = substr($img_data_header, $pos + MARKER_SIZE, LEN_SIZE);
        $len = unpack('n', ($len_str))[1];
        $meta['APP1']['pos'] = $pos;
        $meta['APP1']['len'] = $len;
    }

    $APP2_pos = strpos($img_data_header, MARKER_APP2, $pos);
    if ($APP2_pos) {
        $pos = $APP2_pos;
        $len_str = substr($img_data_header, $pos + MARKER_SIZE, LEN_SIZE);
        $len = unpack('n', ($len_str))[1];
        $meta['APP2']['pos'] = $pos;
        $meta['APP2']['len'] = $len;
    }
    return $meta;
}

/**
search a suitable location for APP2, which is:
 * After APP1 if present.
 * If no APP1 found, after APP0.
 * If no APP0 and no APP1: After SOI
 * If APP2 is present, erase it, including APP marker
@return the position where APP2 should be created
 */
function set_APP2(&$img_data)
{
    $meta = read_meta($img_data);
    $APP2_POS;
    if (array_key_exists('APP2', $meta)) {
        //erase APP2 so we can replace it with our data (UNTESTED)
        $APP2_POS = $meta['APP2']['pos'];
        $img_data = substr_replace($img_data, '', $APP2_POS, $meta['APP2']['len']);
    } else {
        if (array_key_exists('APP0', $meta)) {
            $APP2_POS = $meta['APP0']['pos'] + $meta['APP0']['len'] + MARKER_SIZE;
        } else if (array_key_exists('APP1', $meta)) {
            $APP2_POS = $meta['APP1']['pos'] + $meta['APP1']['len'] + MARKER_SIZE;
        } else {
            $APP2_POS = MARKER_SIZE; //after SOI marker
        }
    }
    return $APP2_POS;
}

function to_mpo($img_data_left, $img_data_right, $filename_out)
{
    //defaults
    $baseline_length = 77;
    $NUMBER_OF_IMAGES = 2;

    // get file content and search a suitable location where to insert APP2.
    $file_size_left = strlen($img_data_left);
    $file_size_right = strlen($img_data_right);

    $APP2_POS_LEFT = set_APP2($img_data_left);
    $APP2_POS_RIGHT = set_APP2($img_data_right);

    //Size of the segments, wihtout the APP marker
    $APP2_size_left = 158;
    $APP2_size_right = 96;

    ////////////////////////////////////////////////////////////////////////////////
    // MP EXTENSION (5.2)

    //// MP FORMAT IDENTIFIER (5.2.1)
    //A Null-Terminated Identifier in ASCII: MPF\0
    $MP_FORMAT_IDENTIFIER = pack("N", 0x4D504600);

    ////MP HEADER (5.2.2)
    //the MP HEADER is composed of the MP_ENDIAN and the OFFSET_TO_FIRST_IFD

    //////MP_ENDIAN (5.2.2.1)
    //we are using LITTLE ENDIANESS: Less Significative Bits first
    $MP_ENDIAN = pack("N", 0x49492A00);

    ////////OFFSET_TO_FIRST_IFD (5.2.2.2)
    //offset of the first IFD. It is at the next Byte
    $OFFSET_TO_FIRST_IFD = pack("V", 0x08);

    ////////////////////////////////////////////////////////////////////////////////
    ////MP INDEX IFD (5.2.3)
    //for the first individual image only. Each field is introduced by a tag.
    //count the number of fields to be declared (Version, Number Of Images, MP Entry)
    $MPI_COUNT = pack("v", 3);

    //Version
    $MPI_VERSION = pack("n", 0x00b0) . //Tag
    pack("v", 0x07) . //Type (undefined)
    pack("V", 4) . //Length of 4 ASCII CHARS
    pack("N", 0x30313030); //Version Number: `0100` in ASCII

    //NUMBER OF IMAGES (5.2.3.2)
    $MPI_NUMBER_OF_IMAGES = pack("n", 0x01b0) . //Tag
    pack("v", 0x04) . //Type: Long
    pack("V", 1) . //count
    pack("V", $NUMBER_OF_IMAGES); //Value

    //OFFSET Of MP Entries values
    $OFFSET_TO_MP_ENTRIES =
    strlen($MP_ENDIAN) +
    strlen($OFFSET_TO_FIRST_IFD) +
    strlen($MPI_COUNT) +
    strlen($MPI_VERSION) +
    strlen($MPI_NUMBER_OF_IMAGES) +
    12 + //MP ENTRY SIZE (declared after)
    4; //Offset of the next IFD (declared after)
    $mpe_tag_count = 16 * $NUMBER_OF_IMAGES;
    $MPE_TAG = pack("n", 0x02b0) . //TAG
    pack("v", 0x07) . //Type (undefined)
    pack("V", $mpe_tag_count) .
    pack("V", $OFFSET_TO_MP_ENTRIES); // 0x46 Offset where are the MPEntries values

    //OFFSET OF NEXT IFD
    // Offset Details:
    // IFD of 16 Bytes per Image:  n * 16 = 32 Bytes. given n = 2 Images
    // + Offset of 50 Bytes
    // TOTAL of 82 Bytes <=> \0x52
    $next_ifd_offset_value = 16 * $NUMBER_OF_IMAGES + $OFFSET_TO_MP_ENTRIES;
    $OFFSET_NEXT_IFD = pack("V", $next_ifd_offset_value); //@0x4a

    //End of the MPIndex IFD
    ////////////////////////////////////////////////////////////////////////////////////
    //////MP ENTRY: one per image (5.2.3.3)
    // OFFSET OF ENDIANESS TAG FROM SOI: 1C
    // SIZE OF FILE 1 = original file size + APP2 size
    // FILE 2 TO ENDIANESS OFFSET = SIZE OF FILE 1 - OFFSET OF ENDIANESS TAG FROM SOI
    //the endianess tag follow the FID offset
    $OFFSET_ENDIANESS_TAG = $APP2_POS_LEFT +
    strlen($MP_FORMAT_IDENTIFIER) +
    strlen($MP_ENDIAN);

    //need the file size with the new APP2 segment size and some offsets
    $file_size_left_with_APP2 = $file_size_left + $APP2_size_left + MARKER_SIZE;

    ////MPI VALUES
    //Individual Image Attributes (5.2.3.3.1) (Figure 8)
    $MPI_VALUES =
    pack("C*",
        0x02, //Type Code (24 bits) (Table 4) (MultiFrameDisparity) @0x4e
        0x00,
        0x02,
        0b10000000) . //3bits:Image Date format, 2 bits:reserved, 3 bits:flags
    pack("V", $file_size_left_with_APP2) . //Individual Image Size (5,2,3,3,2)  @0x52
    pack("C*",
        0x00, //Individual Image Data Offset (5,2,3,3,3) Must be NULL
        0x00,
        0x00,
        0x00,
        0x00, //Independent Image Entry Number 1 (5,2,3,3,4)
        0x00,
        0x00, //Independent Image Entry Number 2
        0x00);

    //Individual Image Attributes (5.2.3.3.1) (Figure 8)
    $file_size_right_with_APP2 = $file_size_right + $APP2_size_right + MARKER_SIZE;
    $MPI_VALUES_B = pack("C*",
        0x02, //Type Code (24 bits) (Table 4) (MultiFrameDisparity)
        0x00,
        0x02,
        0b00000000) . //3bits:Image Date format, 2 bits:reserved, 3 bits:flags
    pack("V", $file_size_right_with_APP2) . //Individual Image Size (5,2,3,3,2)  @0x62
    pack("V",
        $file_size_left_with_APP2 - $OFFSET_ENDIANESS_TAG) . //Individual Image Offset (5,2,3,3,3)
    pack("C*",
        0x00, //Independent Image Entry Number 1 (5,2,3,3,4)
        0x00,
        0x00, //Independent Image Entry Number 2
        0x00);

    ////////////////////////////////////////////////////////////////////////////////////
    //Start of MPAttributes IFD (5.2.4)
    //count the number of fields to be declared
    $MPA_COUNT = pack("v", 4);

    //MP Individual Image Number (5.2.4.2)
    $MPA_INDIVIDUAL_IMAGE_NUMBER = pack("n", 0x01b1) . //Tag
    pack("v", 0x04) . //Type
    pack("V", 0x01) . //Count
    pack("V", 0x01); //Value

    //BASE VIEWPOINT NUMBER (5.2.4.5)  @0x2918
    $MPA_BASE_VIEWPOINT_NUMBER = pack("n", 0x04b2) . //Tag
    pack("v", 0x04) . //Type
    pack("V", 0x01) . //Count
    pack("V", 0x01); //Value

    //MPA Convergence Angle (5.2.4.6)
    $MPA_CONVERGENCE_ANGLE = pack("n", 0x05b2) . //Tag
    pack("v", 0x0a) . //Type: SRATIONAL
    pack("V", 0x01) . //Count
    pack("V", 0x88); //Offset Value

    //MP Baseline Length (5.2.4.7)
    $MPA_BASELINE_LENGTH = pack("n", 0x06b2) . //Tag
    pack("v", 0x05) . //Type: RATIONAL
    pack("V", 0x1) . //Count
    pack("V", 0x90); //offset value

    $OFFSET_NEXT_IFD_NULL = pack("N", 0);

    //MP ATTRIBUT VALUES IFD
    $MPA_VALUES = pack("C*",
        0x00, //Convergence angle (5,2,4,6)
        0x00,
        0x00,
        0x00,
        0x01,
        0x00,
        0x00,
        0x00) .
    pack("V", $baseline_length) . //Baseline length (5,2,4,7)
    pack("N", 0xe8030000);

    ///////////////////////////////////////////////////////////////////////////////
    //data to be inserted in APP2 Segments of the right image
    //Only differant records from the first image record will be created.
    //We will be using the MPI version tag of the first image as the MPA version tag for the second image.
    $MPA_COUNT_B = pack("n", 0x0500); //@0x28fe

    //MP Individual Image Number (5.2.4.2)
    $MPA_INDIVIDUAL_IMAGE_NUMBER_B = pack("n", 0x01b1) . //Tag
    pack("v", 0x04) . //Type
    pack("V", 1) . //Count
    pack("V", 2); //value

    //MPA Convergence Angle (5.2.4.6) @0x2924
    $MPA_CONVERGENCE_ANGLE_B = pack("n", 0x05b2) . //Tag
    pack("v", 0x0a) . //Type: SRATIONAL
    pack("V", 1) . //Count
    pack("V", 0x4a); //Offset Value

    //MP Baseline Length (5.2.4.7)
    $MPA_BASELINE_LENGTH_B = pack("n", 0x06b2) . //Tag
    pack("v", 0x05) . //Type: RATIONAL
    pack("V", 1) . //Count
    pack("V", 0x52); //Offset value

    //////////////////////////// Insert binary data into the left image data in APP2
    $segdata_left = MARKER_APP2 .
    pack("n", $APP2_size_left) .
        $MP_FORMAT_IDENTIFIER .
        $MP_ENDIAN .
        $OFFSET_TO_FIRST_IFD .
        $MPI_COUNT .
        $MPI_VERSION .
        $MPI_NUMBER_OF_IMAGES .
        $MPE_TAG .
        $OFFSET_NEXT_IFD .
        $MPI_VALUES .
        $MPI_VALUES_B .
        $MPA_COUNT .
        $MPA_INDIVIDUAL_IMAGE_NUMBER .
        $MPA_BASE_VIEWPOINT_NUMBER .
        $MPA_CONVERGENCE_ANGLE .
        $MPA_BASELINE_LENGTH .
        $OFFSET_NEXT_IFD_NULL .
        $MPA_VALUES;

    $segdata_right = MARKER_APP2 .
    pack("n", $APP2_size_right) .
    $MP_FORMAT_IDENTIFIER .
    $MP_ENDIAN .
    $OFFSET_TO_FIRST_IFD .
    $MPA_COUNT_B .
    $MPI_VERSION . //MPI version first picture = MPA version second picture
    $MPA_INDIVIDUAL_IMAGE_NUMBER_B .
        $MPA_BASE_VIEWPOINT_NUMBER .
        $MPA_CONVERGENCE_ANGLE_B .
        $MPA_BASELINE_LENGTH_B .
        $OFFSET_NEXT_IFD_NULL .
        $MPA_VALUES;

    //////////////////////////////////////////
    //insert data in the APP2 segment
    $img_data_left = substr_replace($img_data_left,
        $segdata_left,
        $APP2_POS_LEFT,
        0);

    $img_data_right = substr_replace($img_data_right,
        $segdata_right,
        $APP2_POS_RIGHT,
        0);

    //write mpo file
    $mpo = $img_data_left . $img_data_right;
    file_put_contents($filename_out, $mpo);
}
